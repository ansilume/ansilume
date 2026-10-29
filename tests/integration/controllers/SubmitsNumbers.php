<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use yii\web\HttpException;
use yii\web\JsonParser;
use yii\web\Request;
use yii\web\Response;

/**
 * Sends each whole-number field of a request the JSON numbers that no
 * whole number is, and says what the request answered to each: the status
 * and the message, what it wrote to the database (DatabaseWrites) and what
 * it logged as an error or a warning (SendsJsonNumbers::logged()). The body
 * is read by the request's JSON parser (config/web.php: JsonParser), so a
 * number beyond the int range arrives as the float a real request brings,
 * and 1e999 as infinity. Every number runs in a savepoint of the test's
 * transaction that is rolled back after it, so each starts from the same
 * rows. Use in WebControllerTestCase subclasses, which implement
 * answerOf() for what their actions return.
 *
 * An answer reads "<status> <message>", then "; wrote <tables>" and
 * "; logged <messages>" when the request wrote or logged anything. An
 * exception other than an HttpException, such as the ErrorException of an
 * (int) cast of such a float on PHP 8.5, reads "500 <class>: <message> @
 * <file>:<line>".
 */
trait SubmitsNumbers
{
    use SendsJsonNumbers;

    /**
     * The JSON numbers each whole-number field is sent, by their JSON text:
     * the numbers beyond the int range of SendsJsonNumbers, whose (int) cast
     * warns on PHP 8.5, the negative ones of them (-1e30, and -1e999, minus
     * infinity), a fraction, which a cast cuts to another number, and two
     * fractions whose text at PHP's precision of 14 significant digits reads
     * as a whole number ("3", "-3"), which Yii's NumberValidator took for
     * one and a cast then cut to 2 and -2 (WholeNumberValidator refuses
     * them).
     *
     * @return array<string, string>
     */
    protected static function submittedNumbers(): array
    {
        $numbers = [];
        foreach (self::beyondTheIntRangeProvider() as [$number]) {
            $numbers[$number] = $number;
        }

        return $numbers + [
            '-1e30' => '-1e30',
            '-1e999' => '-1e999',
            '12.5' => '12.5',
            '2.9999999999999996' => '2.9999999999999996',
            '-2.9999999999999996' => '-2.9999999999999996',
        ];
    }

    /**
     * What a request with the number at the field answers, by number:
     * $send puts the JSON text of the number into the request, $call runs
     * the request's action.
     *
     * @param callable(string): void $send
     * @param callable(): mixed $call
     * @return array<string, string>
     */
    protected function answersToNumbers(callable $send, callable $call, DatabaseWrites $writes): array
    {
        $before = $writes->snapshot();
        $answers = [];
        foreach (self::submittedNumbers() as $number) {
            $send($number);
            $answers[$number] = $this->inSavepoint(fn (): string => $this->answerTo($call, $writes, $before));
        }

        return $answers;
    }

    /**
     * What the request answers with a whole number at the field, the value
     * $send puts into it: the control that the body reaches the field.
     *
     * @param callable(): void $send
     * @param callable(): mixed $call
     */
    protected function answerToAWholeNumber(callable $send, callable $call, DatabaseWrites $writes): string
    {
        $send();

        return $this->inSavepoint(fn (): string => $this->answerTo($call, $writes, $writes->snapshot()));
    }

    /**
     * The JSON text $json as the body of the next request, as $method, read
     * by the JSON parser of config/web.php (JsonParser).
     */
    protected function sendJsonBody(string $json, string $method): void
    {
        $request = $this->webRequest();
        $request->getHeaders()->set('Content-Type', 'application/json');
        $request->setRawBody($json);
        $body = (new JsonParser())->parse($json, 'application/json');
        $this->assertIsArray($body, 'the body is a JSON object');
        $request->setBodyParams($body);
        $_SERVER['REQUEST_METHOD'] = $method;
    }

    /**
     * The request of the test (WebControllerTestCase installs a web one).
     */
    protected function webRequest(): Request
    {
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);

        return $request;
    }

    /**
     * $body as JSON text with the JSON text $value at $field: "name", a
     * field of an object in it ("approver_config.team_id"), of the first
     * object of a list ("steps[].job_template_id") or an entry added to the
     * end of a list ("credential_ids[]"). $value is written as it is, so it
     * can be a number PHP cannot encode, such as 1e999.
     *
     * @param array<string, mixed> $body
     */
    protected static function bodyWith(array $body, string $field, string $value): string
    {
        $body = self::placed($body, explode('.', $field));

        return str_replace('"@value@"', $value, (string)json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    /**
     * What the action returned, as an answer reads it ("<status> <message>").
     */
    abstract protected function answerOf(mixed $result): string;

    /**
     * Runs $call in a savepoint that is rolled back afterwards, also when
     * the request committed or rolled back more than it began.
     *
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private function inSavepoint(callable $call): mixed
    {
        $db = \Yii::$app->db;
        $level = $db->getTransaction()?->getLevel() ?? 0;
        $db->beginTransaction();
        try {
            return $call();
        } finally {
            for ($transaction = $db->getTransaction(); $transaction !== null && $transaction->getLevel() > $level; $transaction = $db->getTransaction()) {
                $transaction->rollBack();
            }
        }
    }

    /**
     * @param callable(): mixed $call
     * @param array<string, string> $before
     */
    private function answerTo(callable $call, DatabaseWrites $writes, array $before): string
    {
        $response = \Yii::$app->response;
        $this->assertInstanceOf(Response::class, $response);
        $response->clear();
        [$answer, $problems] = $this->logged(function () use ($call): string {
            try {
                return $this->answerOf($call());
            } catch (HttpException $e) {
                return $e->statusCode . ' ' . $e->getMessage();
            } catch (\Throwable $e) {
                return '500 ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . self::placeOf($e);
            }
        });
        $written = DatabaseWrites::written($before, $writes->snapshot());

        return $answer
            . ($written === [] ? '' : '; wrote ' . implode(', ', $written))
            . ($problems === [] ? '' : '; logged ' . implode(' | ', $problems));
    }

    /**
     * Where $e was thrown: in the application, the first place outside the
     * framework and the tests, relative to the application's root.
     */
    private static function placeOf(\Throwable $e): string
    {
        $root = dirname(__DIR__, 3) . '/';
        $frames = array_merge([['file' => $e->getFile(), 'line' => $e->getLine()]], $e->getTrace());
        foreach ($frames as $frame) {
            $file = (string)($frame['file'] ?? '');
            if ($file !== '' && str_starts_with($file, $root) && !preg_match('#^(vendor|tests)/#', substr($file, strlen($root)))) {
                return substr($file, strlen($root)) . ':' . (int)($frame['line'] ?? 0);
            }
        }

        return $e->getFile() . ':' . $e->getLine();
    }

    /**
     * $body with "@value@" at the path $segments.
     *
     * @param array<array-key, mixed> $body
     * @param list<string> $segments
     * @return array<array-key, mixed>
     */
    private static function placed(array $body, array $segments): array
    {
        $segment = (string)array_shift($segments);
        $list = str_ends_with($segment, '[]');
        $key = $list ? substr($segment, 0, -2) : $segment;
        $current = $body[$key] ?? null;
        if ($segments === []) {
            $body[$key] = $list ? array_merge(is_array($current) ? array_values($current) : [], ['@value@']) : '@value@';

            return $body;
        }
        if ($list) {
            $entries = is_array($current) ? array_values($current) : [];
            $entries[0] = self::placed(is_array($entries[0] ?? null) ? $entries[0] : [], $segments);
            $body[$key] = $entries;

            return $body;
        }
        $body[$key] = self::placed(is_array($current) ? $current : [], $segments);

        return $body;
    }
}
