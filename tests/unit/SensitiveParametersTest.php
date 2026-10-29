<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\components\SecretCipher;
use app\models\JobTemplate;
use app\models\PasswordResetForm;
use app\models\traits\TriggerTokenTrait;
use app\models\WorkflowTemplate;
use PHPUnit\Framework\TestCase;
use yii\base\InvalidArgumentException;

/**
 * Parameters that carry a plaintext secret are marked #[\SensitiveParameter],
 * so PHP leaves their values out of stack traces: Yii logs uncaught
 * exceptions with their trace, the trace shows the first characters of every
 * string argument, and the debug error page shows every argument.
 *
 * The application classes (SensitiveParameterScan::DIRECTORIES) are read by
 * reflection:
 * - every marked method parameter is compared with one list, MARKED, so a
 *   mark added or removed anywhere fails until the list says so;
 * - MUST_BE_MARKED names, per class, the parameters that carry passwords,
 *   tokens, other secrets, credentials, survey answers, the runner's claim
 *   payload or job output, so a method added later with such a parameter
 *   fails until it is marked;
 * - class reflection cannot see closures: the marks in each file's source are
 *   counted against MARKED and MARKED_CLOSURES.
 *
 * Regression: every part of the release kept its own list of rows to check,
 * so twelve marked parameters were pinned by none of them, and three helpers
 * of OutputMasker that receive answers (forms(), mayBecomeNeedle(),
 * needleAt()) were not marked at all.
 *
 * Regression: secret parameters outside the survey answer flow were not
 * marked either: user passwords and password reset tokens, API tokens, the
 * LDAP bind and user passwords, TOTP codes and recovery codes, runner tokens
 * and the bootstrap secret, decrypted credentials on their way to Ansible and
 * git, vault passwords, the Redis password, the environment with the
 * server's secrets, and request bodies with passwords or credential secrets.
 * A failed reset link, for one, logged the first 15 characters of its token.
 *
 * Regression (wave 5d): the repository URL, credentials in its userinfo
 * included, was unmarked on the runner's way to git (RunnerProjectSync,
 * GitEnvBuilder), and so were the parameters of the notification channels'
 * post() that carry channel secrets: the webhook URLs and header values,
 * Telegram's URL with the bot token and PagerDuty's body with the routing
 * key. GitSyncDiagnostics::redactUrl(), which every sync path hands the URL,
 * the git command and git's output before it hides their credentials, was
 * unmarked too.
 *
 * Regression (2.12.0 review, SEC-2): rule 11 names static inventory content,
 * but Parse Inventory handed ansible-inventory's output of it, every host
 * with its variables, through unmarked parameters: InventoryService's
 * parseOutput(), extractGroups(), extractHosts() and the checks of the
 * runner's result, AnsibleInventoryRunner's output buffers and the error
 * made of them, and VaultValueMasker, which walks every value.
 */
class SensitiveParametersTest extends TestCase
{
    /**
     * Every marked method parameter, as Class::method($parameter), sorted as
     * strings. A method that classes take from a trait is listed once, under
     * the trait.
     *
     * @var list<string>
     */
    private const MARKED = [
        'app\commands\RunnerController::executeJob($payload)',
        'app\commands\RunnerController::resolveCredentialList($payload)',
        'app\commands\RunnerController::runCommand($cmd)',
        'app\commands\RunnerController::runCommand($env)',
        'app\commands\RunnerController::runCommand($payload)',
        'app\commands\RunnerController::runInJobDirectory($payload)',
        'app\commands\RunnerController::syncProject($payload)',
        'app\commands\RunnerController::vaultMode($payload)',
        'app\commands\RunnerController::writeJobFiles($payload)',
        'app\commands\SetupController::actionAdmin($password)',
        'app\components\ApiBodyValues::listValueRefusal($body)',
        'app\components\CredentialInjectionResult::__construct($env)',
        'app\components\CredentialInjector::inject($credentialData)',
        'app\components\CredentialInjector::injectAll($credentials)',
        'app\components\CredentialInjector::injectSingle($data)',
        'app\components\CredentialInjector::injectSshKey($data)',
        'app\components\CredentialInjector::injectToken($data)',
        'app\components\CredentialInjector::injectUsernamePassword($data)',
        'app\components\CredentialInjector::injectVault($data)',
        'app\components\CredentialInjector::writeTempFile($content)',
        'app\components\CredentialSecretPolicy::missing($provided)',
        'app\components\CredentialSecretPolicy::provided($input)',
        'app\components\ForwardedHeaders::chains($server)',
        'app\components\ForwardedHeaders::header($server)',
        'app\components\ForwardedHeaders::sslFlagged($server)',
        'app\components\GitEnvBuilder::applyHttpsCredentialEnv($env)',
        'app\components\GitEnvBuilder::applyHttpsCredentialEnv($scmCredential)',
        'app\components\GitEnvBuilder::build($scmCredential)',
        'app\components\GitEnvBuilder::build($scmUrl)',
        'app\components\GitEnvBuilder::buildCredentialHelperScript($secret)',
        'app\components\GitEnvBuilder::isHttpsUrl($url)',
        'app\components\GitEnvBuilder::isSshUrl($url)',
        'app\components\GitEnvBuilder::writeSshKeyFile($scmCredential)',
        'app\components\GitSyncDiagnostics::redactUrl($text)',
        'app\components\JobInventory::idOf($payload)',
        'app\components\JobInventory::launchedName($payload)',
        'app\components\JobInventory::ofPayloads($payloads)',
        'app\components\OutputMasker::__construct($answers)',
        'app\components\OutputMasker::feed($bytes)',
        'app\components\OutputMasker::floatForms($answer)',
        'app\components\OutputMasker::forms($answer)',
        'app\components\OutputMasker::mask($text)',
        'app\components\OutputMasker::maskValues($record)',
        'app\components\OutputMasker::mayBecomeNeedle($text)',
        'app\components\OutputMasker::needleAt($text)',
        'app\components\OutputMasker::scan($text)',
        'app\components\PlaybookEnvironment::build($parentEnv)',
        'app\components\PlaybookEnvironment::droppedNames($parentEnv)',
        'app\components\PlaybookEnvironment::extraNames($parentEnv)',
        'app\components\PlaybookEnvironment::startupNotices($parentEnv)',
        'app\components\ProcessStarter::open($command)',
        'app\components\ProcessStarter::open($env)',
        'app\components\PythonFloatText::finite($value)',
        'app\components\PythonFloatText::fixed($digits)',
        'app\components\PythonFloatText::json($value)',
        'app\components\PythonFloatText::nonFinite($value)',
        'app\components\PythonFloatText::repr($value)',
        'app\components\PythonFloatText::scientific($digits)',
        'app\components\PythonFloatText::shortest($value)',
        'app\components\PythonFloatText::yaml($value)',
        'app\components\RedactingRedisConnection::isAuth($params)',
        'app\components\RedactingRedisConnection::redact($message)',
        'app\components\RedactingRedisConnection::sendIgnoringErrors($command)',
        'app\components\RedactingRedisConnection::sendIgnoringErrors($params)',
        'app\components\RedactingRedisConnection::sendRawCommand($command)',
        'app\components\RedactingRedisConnection::sendRawCommand($params)',
        'app\components\RedisSettings::__construct($password)',
        'app\components\RedisSettings::fromEnvironment($env)',
        'app\components\RunnerCommandBuilder::addBecomeFlags($cmd)',
        'app\components\RunnerCommandBuilder::addBecomeFlags($payload)',
        'app\components\RunnerCommandBuilder::addCheckModeFlags($cmd)',
        'app\components\RunnerCommandBuilder::addCheckModeFlags($payload)',
        'app\components\RunnerCommandBuilder::addInventoryArgs($cmd)',
        'app\components\RunnerCommandBuilder::addInventoryArgs($payload)',
        'app\components\RunnerCommandBuilder::addPlaybookOptions($cmd)',
        'app\components\RunnerCommandBuilder::addPlaybookOptions($payload)',
        'app\components\RunnerCommandBuilder::addVerbosityFlag($cmd)',
        'app\components\RunnerCommandBuilder::build($payload)',
        'app\components\RunnerHttpClient::__construct($token)',
        'app\components\RunnerHttpClient::httpPost($body)',
        'app\components\RunnerHttpClient::httpPost($extraHeaders)',
        'app\components\RunnerHttpClient::post($body)',
        'app\components\RunnerHttpClient::postUnauthenticated($body)',
        'app\components\RunnerHttpClient::setToken($token)',
        'app\components\RunnerProcessExecutor::incompleteTail($bytes)',
        'app\components\RunnerProcessExecutor::maskWholeCharacters($chunk)',
        'app\components\RunnerProcessExecutor::postLog($content)',
        'app\components\RunnerProcessExecutor::run($cmd)',
        'app\components\RunnerProcessExecutor::run($env)',
        'app\components\RunnerProcessExecutor::run($payload)',
        'app\components\RunnerProcessExecutor::startProcess($cmd)',
        'app\components\RunnerProcessExecutor::startProcess($env)',
        'app\components\RunnerProcessExecutor::startProcess($payload)',
        'app\components\RunnerProjectSync::buildGitEnv($scmCredential)',
        'app\components\RunnerProjectSync::buildGitEnv($scmUrl)',
        'app\components\RunnerProjectSync::gitCommands($scmUrl)',
        'app\components\RunnerProjectSync::redactGitCmd($cmd)',
        'app\components\RunnerProjectSync::runGitCommand($cmd)',
        'app\components\RunnerProjectSync::runGitCommand($env)',
        'app\components\RunnerProjectSync::runGitCommands($commands)',
        'app\components\RunnerProjectSync::runGitCommands($env)',
        'app\components\RunnerProjectSync::sync($payload)',
        'app\components\RunnerSecretVars::answers($payload)',
        'app\components\RunnerSecretVars::prepare($payload)',
        'app\components\RunnerSecretVars::writePrivateFile($content)',
        'app\components\RunnerTokenResolver::buildRegisterPayload($bootstrapSecret)',
        'app\components\RunnerTokenResolver::cacheToken($token)',
        'app\components\RunnerTokenResolver::extractToken($response)',
        'app\components\RunnerTokenResolver::failPermanently($response)',
        'app\components\RunnerTokenResolver::registrationErrorText($response)',
        'app\components\RunnerTokenResolver::selfRegister($bootstrapSecret)',
        'app\components\RunnerTransportClassifier::changes($server)',
        'app\components\RunnerTransportClassifier::classify($server)',
        'app\components\RunnerTransportClassifier::header($server)',
        'app\components\RunnerVaultMode::apply($command)',
        'app\components\RunnerVaultMode::apply($env)',
        'app\components\RunnerVaultMode::fromPayload($payload)',
        'app\components\SecretCipher::encrypt($plaintext)',
        'app\components\SecretCipher::orFail($result)',
        'app\components\StoredScheduleAnswers::__construct($extraVars)',
        'app\components\SubmittedInteger::refusesLists($values)',
        'app\components\SubprocessEnvironment::build($parentEnv)',
        'app\components\SubprocessEnvironment::isCgiRequestEnvironment($env)',
        'app\components\SurveySecretBackfill::backfillRow($row)',
        'app\components\SurveySecretBackfill::runPage($rows)',
        'app\components\SurveySecretBackfill::stringOrNull($value)',
        'app\components\SurveySecretBackfill::updateRow($columns)',
        'app\components\SurveySecretRowMasker::clearPasswordDefaults($entries)',
        'app\components\SurveySecretRowMasker::decode($json)',
        'app\components\SurveySecretRowMasker::encode($json)',
        'app\components\SurveySecretRowMasker::encode($value)',
        'app\components\SurveySecretRowMasker::hasPasswordDefault($json)',
        'app\components\SurveySecretRowMasker::maskJob($row)',
        'app\components\SurveySecretRowMasker::maskSchedule($row)',
        'app\components\SurveySecretRowMasker::maskSurvey($row)',
        'app\components\SurveySecretRowMasker::masked($json)',
        'app\components\SurveySecretRowMasker::masked($vars)',
        'app\components\SurveySecretRowMasker::maskedCommand($command)',
        'app\components\SurveySecretRowMasker::maskedCommand($old)',
        'app\components\SurveySecretRowMasker::plaintext($vars)',
        'app\components\SurveySecretRowMasker::sealedResult($secret)',
        'app\components\SurveySecretRowMasker::secretsToSeal($found)',
        'app\components\SurveySecretRowMasker::secretsToSeal($row)',
        'app\components\SurveySecretRowMasker::stringOrNull($value)',
        'app\components\SurveySecretRowMasker::withoutPasswordDefaults($json)',
        'app\components\SurveySecretTemplateScan::add($extraVars)',
        'app\components\SurveySecretTemplateScan::add($surveyFields)',
        'app\components\SurveySecretTemplateScan::hasAnyKey($json)',
        'app\components\SurveySecrets::reencode($json)',
        'app\components\SurveySecrets::reveal($secret)',
        'app\components\SurveySecrets::seal($secret)',
        'app\components\SurveySecrets::split($vars)',
        'app\components\TemplateSurveyValidator::decode($json)',
        'app\components\TemplateSurveyValidator::passwordKeys($extraVars)',
        'app\components\TemplateSurveyValidator::sameEntries($baseline)',
        'app\components\TemplateSurveyValidator::sameEntries($values)',
        'app\components\TemplateSurveyValidator::sameExtraVars($baseline)',
        'app\components\TemplateSurveyValidator::sameExtraVars($value)',
        'app\components\TemplateSurveyValidator::sameScalar($baseline)',
        'app\components\TemplateSurveyValidator::sameScalar($value)',
        'app\components\TemplateSurveyValidator::sameValue($baseline)',
        'app\components\TemplateSurveyValidator::sameValue($value)',
        'app\components\TemplateSurveyValidator::textOrNull($value)',
        'app\components\TriggerTokenResolver::bearerCredentials($authorization)',
        'app\components\VaultCheckOutcome::decide($password)',
        'app\components\VaultCheckOutcome::verify($password)',
        'app\components\VaultCheckRun::check($password)',
        'app\components\VaultCheckRun::opens($password)',
        'app\components\VaultCheckRun::unopened($password)',
        'app\components\VaultIsolation::mentionsDecryptionFailure($output)',
        'app\components\VaultValueMasker::isVaultObject($value)',
        'app\components\VaultValueMasker::mask($value)',
        'app\components\WebLaunchOverrides::fromRequest($overrides)',
        'app\components\WebLaunchOverrides::fromRequest($survey)',
        'app\components\vault\VaultEnvelope::normalizePassword($password)',
        'app\components\vault\VaultEnvelope::opens($password)',
        'app\controllers\SiteController::actionResetPassword($token)',
        'app\controllers\TriggerController::notifyUnknownToken($token)',
        'app\controllers\api\runner\BaseRunnerApiController::ok($data)',
        'app\controllers\api\runner\RegisterController::extractReportedVersion($body)',
        'app\controllers\api\v1\BaseApiController::success($data)',
        'app\controllers\api\v1\CredentialsController::applyBody($body)',
        'app\controllers\api\v1\CredentialsController::secretsFrom($body)',
        'app\controllers\api\v1\InventoriesController::applyBody($body)',
        'app\controllers\api\v1\JobsController::buildOverrides($body)',
        'app\controllers\api\v1\JobsController::templateIdFrom($body)',
        'app\controllers\api\v1\NotificationTemplatesController::applyBody($body)',
        'app\controllers\api\v1\ProjectsController::applyBody($body)',
        'app\controllers\api\v1\SchedulesController::applyBody($body)',
        'app\controllers\api\v1\UsersController::applyAuthSourceOnCreate($body)',
        'app\controllers\api\v1\UsersController::applyBody($body)',
        'app\controllers\api\v1\UsersController::applyLdapMetadata($body)',
        'app\controllers\api\v1\UsersController::applyPasswordOnUpdate($body)',
        'app\controllers\api\v1\UsersController::assignRole($body)',
        'app\controllers\api\v1\UsersController::rejectAuthSourceChange($body)',
        'app\controllers\api\v1\WebhooksController::applyBody($body)',
        'app\helpers\FileHelper::safeFilePutContents($content)',
        'app\models\ApiToken::findByRawToken($raw)',
        'app\models\PasswordResetForm::__construct($token)',
        'app\models\PasswordResetRequestForm::buildResetUrl($token)',
        'app\models\Runner::findByToken($rawToken)',
        'app\models\Runner::transportChanges($server)',
        'app\models\TotpSetupForm::__construct($secret)',
        'app\models\User::findByPasswordResetToken($token)',
        'app\models\User::findIdentityByAccessToken($token)',
        'app\models\User::setPassword($password)',
        'app\models\User::validateAuthKey($authKey)',
        'app\models\User::validatePassword($password)',
        'app\models\WorkflowJob::setLaunchExtraVars($vars)',
        'app\models\traits\TriggerTokenTrait::findByTriggerToken($token)',
        'app\services\AnsibleInventoryRunner::drainPipes($stderr)',
        'app\services\AnsibleInventoryRunner::drainPipes($stdout)',
        'app\services\AnsibleInventoryRunner::openProcess($env)',
        'app\services\AnsibleInventoryRunner::result($error)',
        'app\services\AnsibleInventoryRunner::result($stderr)',
        'app\services\AnsibleInventoryRunner::result($stdout)',
        'app\services\AnsibleInventoryRunner::runProcess($env)',
        'app\services\CredentialService::analyzePrivateKey($privateKey)',
        'app\services\CredentialService::encrypt($plaintext)',
        'app\services\CredentialService::encryptSecrets($secrets)',
        'app\services\CredentialService::redact($secrets)',
        'app\services\CredentialService::storeSecrets($secrets)',
        'app\services\CredentialWriteService::buildSecrets($provided)',
        'app\services\CredentialWriteService::create($secretInput)',
        'app\services\CredentialWriteService::update($secretInput)',
        'app\services\GatedInventoryLock::changedAttributes($stored)',
        'app\services\GatedInventoryLock::sameInteger($stored)',
        'app\services\GatedInventoryLock::sameInteger($value)',
        'app\services\GatedInventoryLock::sameValue($stored)',
        'app\services\GatedInventoryLock::sameValue($value)',
        'app\services\GatedJobTemplateLock::changesColumns($stored)',
        'app\services\GatedJobTemplateLock::comparable($value)',
        'app\services\GatedJobTemplateLock::jsonKey($text)',
        'app\services\InventoryRuleAssignment::apply($submitted)',
        'app\services\InventoryService::extractGroups($data)',
        'app\services\InventoryService::extractHosts($data)',
        'app\services\InventoryService::extractHosts($groups)',
        'app\services\InventoryService::fitError($error)',
        'app\services\InventoryService::isEncryptedVarsFailure($result)',
        'app\services\InventoryService::parseOutput($json)',
        'app\services\InventoryService::vaultAwareError($result)',
        'app\services\InventoryWriteService::create($submitted)',
        'app\services\InventoryWriteService::rebase($stored)',
        'app\services\InventoryWriteService::update($submitted)',
        'app\services\JobClaimGate::check($raw)',
        'app\services\JobClaimSecrets::encode($vars)',
        'app\services\JobClaimSecrets::view($secretExtraVars)',
        'app\services\JobClaimService::checkClaimable($raw)',
        'app\services\JobClaimService::resolveInventory($payload)',
        'app\services\JobClaimService::resolveProjectPath($payload)',
        'app\services\JobClaimService::resolveProjectScm($payload)',
        'app\services\JobClaimService::resolveTemplateCredentials($raw)',
        'app\services\JobClaimService::resolveVaultPasswordSource($payload)',
        'app\services\JobCompletionService::appendLog($content)',
        'app\services\JobCompletionService::persistTask($data)',
        'app\services\JobCompletionService::saveTasks($tasks)',
        'app\services\JobLaunchInput::check($overrides)',
        'app\services\JobLaunchInput::errors($overrides)',
        'app\services\JobLaunchSecrets::apply($merged)',
        'app\services\JobLaunchSecrets::encode($vars)',
        'app\services\JobLaunchService::applyExtraVars($overrides)',
        'app\services\JobLaunchService::buildJobRecord($overrides)',
        'app\services\JobLaunchService::contextFrom($overrides)',
        'app\services\JobLaunchService::launch($overrides)',
        'app\services\JobLaunchService::mergeExtraVars($overrides)',
        'app\services\LaunchContext::__construct($carriedSecrets)',
        'app\services\LaunchContext::forRelaunch($secrets)',
        'app\services\LaunchContext::forSchedule($secrets)',
        'app\services\LaunchContext::forWorkflow($secrets)',
        'app\services\LaunchContext::keysOf($secrets)',
        'app\services\LintService::runProcess($env)',
        'app\services\ProjectService::applyHttpsCredentialEnv($env)',
        'app\services\ProjectService::buildCredentialHelperScript($secret)',
        'app\services\ProjectService::cloneOrPull($env)',
        'app\services\ProjectService::gitClone($env)',
        'app\services\ProjectService::gitClone($url)',
        'app\services\ProjectService::gitPull($env)',
        'app\services\ProjectService::gitPull($url)',
        'app\services\ProjectService::runGit($cmd)',
        'app\services\ProjectService::runGit($env)',
        'app\services\ProjectSyncProcessRunner::appendLog($content)',
        'app\services\ProjectSyncProcessRunner::appendSystem($message)',
        'app\services\ProjectSyncProcessRunner::run($cmd)',
        'app\services\ProjectSyncProcessRunner::run($env)',
        'app\services\ProjectSyncProcessRunner::write($text)',
        'app\services\RelaunchService::extraVars($secrets)',
        'app\services\RunnerReportNumbers::taskListRefusal($tasks)',
        'app\services\ScheduleRunAs::canonical($text)',
        'app\services\ScheduleRunAs::varsKey($text)',
        'app\services\ScheduleSecrets::answers($vars)',
        'app\services\ScheduleSecrets::changeOf($value)',
        'app\services\ScheduleSecrets::classify($vars)',
        'app\services\ScheduleSecrets::store($secrets)',
        'app\services\TotpService::buildProvisioningUri($secret)',
        'app\services\TotpService::enable($plainSecret)',
        'app\services\TotpService::encryptSecret($plainSecret)',
        'app\services\TotpService::generateQrDataUri($provisioningUri)',
        'app\services\TotpService::useRecoveryCode($code)',
        'app\services\TotpService::verifyCode($code)',
        'app\services\TotpService::verifyCode($secret)',
        'app\services\TotpService::verifyRecoveryCode($code)',
        'app\services\WorkflowExecutionService::createRun($overrides)',
        'app\services\WorkflowExecutionService::executeStep($mappedVars)',
        'app\services\WorkflowExecutionService::launch($overrides)',
        'app\services\WorkflowRunVars::assertEncodable($split)',
        'app\services\WorkflowRunVars::jobOverrides($mappedVars)',
        'app\services\WorkflowRunVars::objectOf($value)',
        'app\services\WorkflowRunVars::objectOfText($json)',
        'app\services\WorkflowRunVars::storeLaunchVars($overrides)',
        'app\services\WorkflowStepDispatcher::dispatch($mappedVars)',
        'app\services\WorkflowStepDispatcher::jobStep($mappedVars)',
        'app\services\ldap\FakeLdapClient::addUser($password)',
        'app\services\ldap\FakeLdapClient::bindAsUser($password)',
        'app\services\ldap\LdapClientInterface::bindAsUser($password)',
        'app\services\ldap\LdapConfig::__construct($bindPassword)',
        'app\services\ldap\LdapConfig::fromArray($params)',
        'app\services\ldap\LdapService::authenticate($password)',
        'app\services\ldap\PhpLdapClient::bindAsUser($password)',
        'app\services\notification\PagerDutyChannel::post($payload)',
        'app\services\notification\SlackChannel::post($url)',
        'app\services\notification\TeamsChannel::post($url)',
        'app\services\notification\TelegramChannel::post($url)',
        'app\services\notification\WebhookChannel::post($headers)',
        'app\services\notification\WebhookChannel::post($url)',
    ];

    /**
     * Marked parameters of closures and arrow functions, which class
     * reflection cannot see, per class: SurveySecretBackfill's masking
     * closures take a job, schedule or template row, and VaultValueMasker's
     * takes each value of a parsed inventory.
     *
     * @var array<string, list<string>>
     */
    private const MARKED_CLOSURES = [
        'app\components\SurveySecretBackfill' => ['row', 'row', 'row'],
        'app\components\VaultValueMasker' => ['item'],
    ];

    /**
     * Per class, the parameter names that carry passwords, tokens, other
     * secrets, credentials, survey answers, the runner's claim payload
     * (answers and credentials) or job output: every parameter of such a name
     * in a method of the class must be marked, also in methods added later.
     * Some rules have no such parameter today (ScheduleLaunchSecrets) and only
     * guard new methods.
     *
     * @var array<class-string, list<string>>
     */
    private const MUST_BE_MARKED = [
        // The runner: the claim payload, and the command and environment built from it.
        'app\commands\RunnerController' => ['payload', 'cmd', 'env'],
        'app\components\RunnerCommandBuilder' => ['payload', 'cmd'],
        'app\components\RunnerProcessExecutor' => ['cmd', 'payload', 'env', 'chunk', 'bytes', 'content'],
        // RunnerProjectSync: also the repository URL with the credentials of its userinfo, the git commands that
        // hold it, and the environment with the SCM credential.
        'app\components\RunnerProjectSync' => ['payload', 'scmCredential', 'scmUrl', 'cmd', 'commands', 'env'],
        'app\components\RunnerVaultMode' => ['payload', 'command', 'env'],
        'app\components\RunnerSecretVars' => ['payload', 'json', 'content'],
        'app\components\RunnerHttpClient' => ['token', 'body', 'extraHeaders'],
        'app\components\OutputMasker' => ['answers', 'answer', 'bytes', 'text', 'record'],
        // A float password answer, and its digits, on their way to the masker's needles.
        'app\components\PythonFloatText' => ['value', 'digits'],
        // The runner's token, its bootstrap secret, and the environment with the runner's or the server's secrets.
        'app\components\RunnerTokenResolver' => ['bootstrapSecret', 'token', 'response'],
        'app\components\PlaybookEnvironment' => ['parentEnv'],
        'app\components\SubprocessEnvironment' => ['parentEnv', 'env'],
        // Every proc_open() call of the application: a git command holds the repository URL with the credentials of its
        // userinfo, and the environment holds the SCM credential, the vault isolation and the job's credentials.
        'app\components\ProcessStarter' => ['command', 'env'],
        // Decrypted credentials on their way to Ansible and git, and the files and environment made from them.
        'app\components\CredentialInjector' => ['credentialData', 'credentials', 'data', 'content'],
        'app\components\CredentialInjectionResult' => ['env'],
        'app\components\GitEnvBuilder' => ['scmCredential', 'secret', 'env', 'scmUrl', 'url'],
        // redactUrl($text): a URL, a git command or git's output with the credentials of its URLs, before it hides them.
        'app\components\GitSyncDiagnostics' => ['text'],
        // The repository URL with the credentials of its userinfo, the git command that holds it, and git's
        // output before it is redacted (the sync output, which can name the URL).
        'app\services\ProjectService' => ['secret', 'env', 'url', 'cmd'],
        'app\services\ProjectSyncProcessRunner' => ['env', 'cmd', 'text', 'content', 'message'],
        'app\helpers\FileHelper' => ['content'],
        // The runner API: the claim payload, the bootstrap secret, the bearer token in $_SERVER, job output.
        'app\controllers\api\runner\BaseRunnerApiController' => ['data'],
        'app\controllers\api\runner\RegisterController' => ['body'],
        'app\models\Runner' => ['rawToken', 'server'],
        'app\components\RunnerTransportClassifier' => ['server'],
        'app\components\ForwardedHeaders' => ['server'],
        'app\services\JobCompletionService' => ['content', 'tasks', 'data'],
        // The task results a runner reports (job output), checked before they are saved.
        'app\services\RunnerReportNumbers' => ['tasks'],
        // Launches: request data with answers, and the answers carried by a relaunch or a schedule.
        'app\components\WebLaunchOverrides' => ['overrides', 'survey'],
        'app\controllers\api\v1\JobsController' => ['body'],
        'app\services\JobLaunchService' => ['overrides'],
        'app\services\JobLaunchInput' => ['overrides'],
        'app\services\JobLaunchSecrets' => ['merged', 'vars'],
        'app\services\WorkflowExecutionService' => ['overrides', 'mappedVars'],
        // A workflow run's launch extra vars with their password answers, and a step's mapping of step output.
        'app\services\WorkflowRunVars' => ['overrides', 'mappedVars', 'value', 'json', 'split', 'vars', 'secrets'],
        // The public part of a workflow run's launch extra vars: survey answers, and whatever a caller sends.
        'app\models\WorkflowJob' => ['vars'],
        'app\services\WorkflowStepDispatcher' => ['mappedVars'],
        'app\services\LaunchContext' => ['secrets', 'carriedSecrets'],
        'app\services\RelaunchService' => ['secrets'],
        'app\services\JobClaimSecrets' => ['vars', 'secretExtraVars'],
        // The claim's checks and resolvers read the job's decoded runner payload, whose extra vars hold the launch's
        // survey answers.
        'app\services\JobClaimGate' => ['raw'],
        'app\services\JobClaimService' => ['raw', 'payload'],
        // The inventory a job was launched with, read from its decoded runner payload, whose extra vars hold the
        // launch's survey answers.
        'app\components\JobInventory' => ['payload', 'payloads'],
        // reencode($json): the extra vars as stored or submitted, with plaintext answers.
        'app\components\SurveySecrets' => ['secret', 'json'],
        'app\components\SecretCipher' => ['plaintext', 'result'],
        // Schedules.
        'app\controllers\api\v1\SchedulesController' => ['body'],
        'app\services\ScheduleSecrets' => ['vars', 'secrets', 'secret', 'answers', 'value'],
        'app\services\ScheduleLaunchSecrets' => ['vars', 'secrets', 'secret', 'answers'],
        'app\components\StoredScheduleAnswers' => ['extraVars'],
        // ScheduleRunAs compares the stored and the submitted extra vars, which can hold plaintext answers.
        'app\services\ScheduleRunAs' => ['text'],
        // The backfill: rows, columns and JSON with plaintext answers or password defaults.
        'app\components\SurveySecretBackfill' => ['row', 'rows', 'columns'],
        'app\components\SurveySecretRowMasker' => ['row', 'json', 'vars', 'secret', 'entries', 'found', 'command', 'old', 'value'],
        'app\components\SurveySecretTemplateScan' => ['surveyFields', 'extraVars', 'json'],
        // A legacy template's Extra Vars, which can hold a plaintext password default.
        'app\components\TemplateSurveyValidator' => ['extraVars', 'json', 'value', 'values', 'baseline'],
        // The gated-template lock compares a template's stored row and submitted columns, the Extra Vars among them.
        'app\services\GatedJobTemplateLock' => ['stored', 'value', 'text'],
        // Inventory writes: the form's fields or the request body (static inventory content among them), the
        // inventory's row as stored or locked, and the values the inventory lock compares.
        'app\services\InventoryWriteService' => ['submitted', 'stored'],
        'app\services\InventoryRuleAssignment' => ['submitted'],
        'app\services\GatedInventoryLock' => ['stored', 'value'],
        'app\controllers\api\v1\InventoriesController' => ['body'],
        // Parse Inventory: ansible-inventory's output of an inventory (static inventory content among it, every host
        // with its variables), the result and the error made of it, and the values the vault masker walks.
        'app\services\InventoryService' => ['json', 'data', 'groups', 'result', 'error'],
        'app\services\AnsibleInventoryRunner' => ['stdout', 'stderr', 'error', 'env'],
        // Lint: the environment of ansible-lint, with the proxy URLs (credentials in their userinfo) and ANSIBLE_* settings of the host.
        'app\services\LintService' => ['env'],
        'app\components\VaultValueMasker' => ['value'],
        'app\components\VaultIsolation' => ['output'],
        // Trigger tokens, and the other secrets whose cipher the release shares.
        'app\controllers\TriggerController' => ['token'],
        'app\components\TriggerTokenResolver' => ['authorization', 'token'],
        'app\models\traits\TriggerTokenTrait' => ['token'],
        'app\services\CredentialService' => ['secrets', 'plaintext', 'privateKey'],
        'app\services\TotpService' => ['plainSecret', 'secret', 'code', 'provisioningUri'],
        // Redis: the password, the environment it comes from, and AUTH commands and their error messages.
        'app\components\RedisSettings' => ['password', 'env'],
        'app\components\RedactingRedisConnection' => ['command', 'params', 'message'],
        // Users: passwords, password reset tokens, the remember-me key, API tokens, TOTP secrets.
        'app\models\User' => ['password', 'token', 'authKey'],
        'app\models\ApiToken' => ['raw'],
        'app\models\PasswordResetForm' => ['token'],
        'app\models\PasswordResetRequestForm' => ['token'],
        'app\models\TotpSetupForm' => ['secret'],
        'app\controllers\SiteController' => ['token'],
        'app\commands\SetupController' => ['password'],
        // LDAP: the user's password and the service account's bind password.
        'app\services\ldap\LdapService' => ['password'],
        'app\services\ldap\LdapClientInterface' => ['password'],
        'app\services\ldap\PhpLdapClient' => ['password'],
        'app\services\ldap\FakeLdapClient' => ['password'],
        'app\services\ldap\LdapConfig' => ['bindPassword', 'params'],
        // Vault passwords.
        'app\components\VaultCheckOutcome' => ['password'],
        'app\components\VaultCheckRun' => ['password'],
        'app\components\vault\VaultEnvelope' => ['password'],
        // The REST API: request bodies with passwords, credential secrets or a webhook's signing secret, and
        // responses with a new token.
        'app\controllers\api\v1\UsersController' => ['body'],
        'app\controllers\api\v1\CredentialsController' => ['body'],
        'app\controllers\api\v1\WebhooksController' => ['body'],
        // Request bodies with the channel secrets of a notification template, or credentials in a repository URL.
        'app\controllers\api\v1\NotificationTemplatesController' => ['body'],
        'app\controllers\api\v1\ProjectsController' => ['body'],
        'app\controllers\api\v1\BaseApiController' => ['data'],
        // A request body checked for lists and objects before it reaches the model, static inventory content among
        // its values.
        'app\components\ApiBodyValues' => ['body'],
        // A request body or a form's posted fields, checked for lists where whole numbers belong.
        'app\components\SubmittedInteger' => ['values'],
        'app\services\CredentialWriteService' => ['secretInput', 'provided'],
        'app\components\CredentialSecretPolicy' => ['input', 'provided'],
        // Notification channels: what carries their channel secrets (NotificationTemplate::SECRET_CONFIG_KEYS,
        // SECRET_CONFIG_MAPS): the webhook URLs, the webhook channel's header values, Telegram's URL with the
        // bot token, and PagerDuty's body with the routing key.
        'app\services\notification\SlackChannel' => ['url'],
        'app\services\notification\TeamsChannel' => ['url'],
        'app\services\notification\WebhookChannel' => ['url', 'headers'],
        'app\services\notification\TelegramChannel' => ['url'],
        'app\services\notification\PagerDutyChannel' => ['payload'],
    ];

    public function testEveryMarkedParameterIsListed(): void
    {
        $classes = SensitiveParameterScan::classesByFile();
        $labels = array_column(SensitiveParameterScan::markedMethodParameters($classes), 'label');

        $this->assertSame(
            ['marked, not in MARKED' => [], 'in MARKED, not marked' => []],
            [
                'marked, not in MARKED' => array_values(array_diff($labels, self::MARKED)),
                'in MARKED, not marked' => array_values(array_diff(self::MARKED, $labels)),
            ],
            'A #[\SensitiveParameter] was added or removed: update MARKED, and MUST_BE_MARKED when the parameter carries a secret.'
        );
        $sorted = array_values(array_unique(self::MARKED));
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, self::MARKED, 'MARKED is sorted as strings, without duplicates');
    }

    /**
     * Every mark in the source is a parameter of a listed method or of a
     * listed closure: a mark on a closure is neither lost nor added unseen.
     */
    public function testEveryMarkInTheSourceIsAListedMethodOrClosureParameter(): void
    {
        $classes = SensitiveParameterScan::classesByFile();
        $reflected = [];
        foreach (SensitiveParameterScan::markedMethodParameters($classes) as $parameter) {
            $reflected[$parameter['file']][] = $parameter['name'];
        }
        $names = array_map(static fn (\ReflectionClass $class): string => $class->getName(), $classes);
        $this->assertSame([], array_values(array_diff(array_keys(self::MARKED_CLOSURES), $names)), 'MARKED_CLOSURES names application classes');

        $mismatches = [];
        foreach ($classes as $file => $class) {
            $inSource = SensitiveParameterScan::markedNamesInSource($file);
            $listed = array_merge($reflected[$file] ?? [], self::MARKED_CLOSURES[$class->getName()] ?? []);
            sort($inSource, SORT_STRING);
            sort($listed, SORT_STRING);
            if ($inSource !== $listed) {
                $mismatches[$class->getName()] = ['in the source' => $inSource, 'methods and MARKED_CLOSURES' => $listed];
            }
        }

        $this->assertSame([], $mismatches, 'Every #[\SensitiveParameter] in the source marks a method parameter or a closure parameter of MARKED_CLOSURES.');
    }

    /**
     * The guard for omissions: a parameter that carries a secret in one of
     * these classes is marked, whatever method it belongs to.
     */
    public function testParametersThatCarrySecretsAreMarked(): void
    {
        $unmarked = [];
        foreach (self::MUST_BE_MARKED as $className => $names) {
            $class = new \ReflectionClass($className);
            foreach ($class->getMethods() as $method) {
                if ($method->getFileName() !== $class->getFileName()) {
                    continue;
                }
                foreach ($method->getParameters() as $parameter) {
                    if (in_array($parameter->getName(), $names, true) && $parameter->getAttributes(\SensitiveParameter::class) === []) {
                        $unmarked[] = "{$className}::{$method->getName()}(\${$parameter->getName()})";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $unmarked,
            'These parameters carry passwords, tokens, secrets, credentials, answers, the claim payload or job output:'
            . ' mark them #[\SensitiveParameter].'
        );
    }

    /**
     * JobTemplate and WorkflowTemplate take findByTriggerToken() from
     * TriggerTokenTrait: reflection shows it on both, with the trait's file
     * and line, and the scan lists it once, under the trait.
     */
    public function testATraitsMethodIsListedOnceUnderTheTrait(): void
    {
        $trait = new \ReflectionMethod(TriggerTokenTrait::class, 'findByTriggerToken');
        foreach ([JobTemplate::class, WorkflowTemplate::class] as $class) {
            $method = new \ReflectionMethod($class, 'findByTriggerToken');
            $this->assertSame([$trait->getFileName(), $trait->getStartLine()], [$method->getFileName(), $method->getStartLine()], $class);
        }

        $classes = SensitiveParameterScan::classesByFile();
        $labels = array_column(SensitiveParameterScan::markedMethodParameters($classes), 'label');
        $this->assertSame(
            ['app\models\traits\TriggerTokenTrait::findByTriggerToken($token)'],
            array_values(array_filter($labels, static fn (string $label): bool => str_contains($label, '::findByTriggerToken(')))
        );
    }

    /**
     * What the mark does: the value is replaced in the trace.
     *
     * The images set zend.exception_ignore_args = On, which leaves every
     * argument out of traces. phpunit.xml turns it off for the tests, as on a
     * host whose php.ini leaves it off: there the mark alone keeps the value
     * out. The trace tests turn it off themselves too, so they hold under
     * another configuration.
     */
    public function testAMarkedValueStaysOutOfTheTrace(): void
    {
        $original = $_ENV['APP_SECRET_KEY'] ?? null;
        unset($_ENV['APP_SECRET_KEY']);
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        try {
            (new SecretCipher())->encrypt('trace-marker-value-6170');
            $this->fail('expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SecretCipher->encrypt(Object(SensitiveParameterValue))', $e->getTraceAsString());
            $this->assertStringNotContainsString('trace-marker', (string)$e);
        } finally {
            if ($ignoreArgs !== false) {
                ini_set('zend.exception_ignore_args', $ignoreArgs);
            }
            if ($original !== null) {
                $_ENV['APP_SECRET_KEY'] = $original;
            }
        }
    }

    /**
     * Regression: a reset link with an unknown or expired token put the first
     * 15 characters of the token into the trace that Yii logs. Arguments are
     * on in traces here, as in testAMarkedValueStaysOutOfTheTrace().
     */
    public function testAPasswordResetTokenStaysOutOfTheTrace(): void
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        try {
            // Expired (the timestamp after the last "_" is 0), so no user is looked up.
            new PasswordResetForm('trace-marker-reset-token-5309_0');
            $this->fail('expected an exception');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString(
                'PasswordResetForm->__construct(Object(SensitiveParameterValue))',
                $e->getTraceAsString()
            );
            $this->assertStringNotContainsString('trace-marker', (string)$e);
        } finally {
            if ($ignoreArgs !== false) {
                ini_set('zend.exception_ignore_args', $ignoreArgs);
            }
        }
    }
}
