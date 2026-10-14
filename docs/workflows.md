# Workflows

A workflow template chains job templates, approval steps and pause steps
into one run. This guide shows how to build one, how a run goes from step to
step, what a run that waits is waiting for, who may do what, and how to do
all of it through the API. What changed with 2.11.0 is listed in
[updating.md](updating.md#after-updating-from-2101-or-older-workflow-steps-and-waiting-runs);
what to do when something goes wrong, in
[troubleshooting.md](troubleshooting.md#a-workflow-run-seems-stuck).

## Concepts

- **Workflow template**: a name, a description, an optional trigger token
  and a list of steps. The sidebar lists them under **Workflow Templates**.
- **Step**: one entry of the list, of one of three types:
  - a **job step** launches a job template and succeeds or fails with its
    job;
  - an **approval step** waits until the approvers of an approval rule
    approve, and fails when the request is rejected, times out or is
    canceled;
  - a **pause step** waits until someone resumes the run.
- **Gated job step**: a job step whose job template has an approval rule.
  Its job waits for an approval before it runs; the steps table marks it
  `needs approval`.
- **Position**: a step's place in the list, from 1 to the number of steps.
  The list is the order runs take the steps: a run starts with position 1,
  the **start step**, marked `Start`.
- **Branch**: where a run goes after a step: On Success, On Failure and
  Always (see [Branches](#branches-where-a-run-goes-next)).
- **Workflow Job**: one run of a workflow template. The sidebar lists the
  runs under **Workflow Jobs**. A run keeps the name, type, status and job
  of every step it executed, also when the step is edited or removed later.

## Building a workflow

Create the workflow under **Workflow Templates** with a name and a
description, then add its steps on its page with the **Add Step** form below
the steps table:

- **Name** and **Step Type**: Job, Approval or Pause. Approval is offered
  only to admins (see [Who may do what](#who-may-do-what)).
- **Job Template** for a job step, **Approval Rule** for an approval step.
  Users without `approval-rule.update` are offered only job templates
  without an approval rule, and read
  `Job templates with an approval rule are not listed: only admins may add a job step for them.`
- **Position**: where the step goes. `At the end (after 5. Rollback)` is the
  default; `At the start (runs will start with this step)` and, for every
  step but the last, `After 3. Deploy` put it elsewhere. A line below the
  field says where the step will run, and follows the branches:
  `Runs after "Deploy", before "Verify".` when a success of the step above
  goes on with the new step, and otherwise, for example,
  `Listed after "Verify", but runs end when "Verify" succeeds, skipping this step.`,
  `Listed after "Build", but a successful "Build" goes on with "Deploy", skipping this step.`
  or
  `Listed after "Build", which cannot run: a run fails there instead of going on to this step.`
  A warning appears when the choice changes the start step, when runs
  would reach a job step, the new one included, without passing the
  approval step (see [Moving steps](#moving-steps)), or, for an approval
  step, when no success would lead to the place:
  `This approval step will not be on the success path: runs whose steps all succeed will not wait for it.`
  The form shows it before the step is added, while the type is Approval.
  The first step of a workflow has no Position field, only the note
  `The first step: runs will start with it.`
- **On Success → go to**, **On Failure → go to** and
  **Always → go to (overrides success/failure)**: the branches (see
  [Branches](#branches-where-a-run-goes-next)). Their empty choice names
  the step it leads to, such as `Next in the list: "Verify"`, or reads
  `Next in the list: none, the run succeeds` when the new step will be the
  last. A pause step has only the first, labelled `After Resume → go to`.

A new job step ends the workflow when it fails: the form preselects
`End the workflow: the run fails` under On Failure and says
`A new job step ends the workflow when it fails. Choose "Next in the list" to go on instead.`
Only the form has this default: a job step added through the API, and
every job step that existed before 2.11.0, keeps an empty On Failure unless
one is set, and goes on with the next step also after a failure.

**+ Insert below** in a step's row opens the form with Position set to
after that step (to the end for the last step) and the cursor in Name.

After **Add Step** the page shows the new step's row, highlighted, and the
flash says where the step landed and how the empty branches of other steps
now lead. Inserting "Smoke test" below step 3 of the example under
[Branches](#branches-where-a-run-goes-next) reads
`Step "Smoke test" added at position 4 of 6, after "Deploy". "Deploy" now continues with "Smoke test" (was "Verify").`
A refused add says why, after `Step not added: `. Each add is audited as
`workflow-template.step.added`.

## Positions and the run order

The `#` column of the steps table is the position (its title reads
`Position: runs start with step 1.`). A run starts with step 1, marked
`Start` (`Runs start with this step.`), and after each step follows the
step's branch. The legend below the table sums it up:
`A run starts with step 1. An empty branch goes on with the next step in the list, marked (next), and changes when steps move. ↺ marks a branch back to the same or an earlier step.`

Labels, flashes and refusals name a step by its position: `3. Deploy` in
the table and the menus, `step 3 "Deploy"` in sentences. A position changes
when steps move; where a message needs the step's ID, it writes
`step ID 43`, so that it is not taken for a position, or for the number of a
run, a job or an approval request such as `#43`.

The **success path** above the table lists the steps a run passes when
every step succeeds, for example
`Success path: 1. Build → 2. Release approval (approval step) → 3. Deploy → 4. Verify → end · run succeeds`.
When the path leads back to a step it passed, it ends with that step marked
↺, and the page adds
`When every step succeeds, the run repeats these steps until one fails or the run is canceled.`
A path that reaches a step that cannot run ends with that step, such as
`4. Verify cannot run · run fails`.

A step that no run can reach is marked `unreachable`
(`No branch that a run takes leads to this step, so runs never start it.`):
no branch of a step that runs reach leads to it, not even an empty one,
where a branch that an Always branch overrides, and the On Failure of a
pause step, are not taken. A step that cannot run leads nowhere, as a run
fails there, so a step that only such a step leads to is unreachable too.
A step that cannot run shows why in its Target column, and a run that
reaches it fails there:
`job template ID 12 no longer exists · runs fail here`,
`no job template · runs fail here`,
`approval rule ID 9 no longer exists · runs fail here`,
`no approval rule · runs fail here` or
`unknown step type · runs fail here`.

Each step stores a step order, which the API lists as `step_order`: an
internal sort key, not a position. Every step that is added, moved or
removed on the page or through the step endpoints renumbers the workflow's
steps 10, 20, 30… in their run order; an edit that keeps the step's place,
and a move to where it stands, renumber nothing, and a `steps` list of the
API numbers them 0, 1, 2…. Steps that share a step order run in the order
of their IDs, as the list shows them; the query under **Steps that share a
step order** in
[updating.md](updating.md#after-updating-from-2101-or-older-workflow-steps-and-waiting-runs)
finds them.

## Branches: where a run goes next

Every step has three branches:

- **On Success** is taken when the job succeeds, the approval is granted or
  the run is resumed.
- **On Failure** is taken when the job fails, and when an approval step's or
  a gated job step's approval is rejected, times out or is canceled (a
  closed gate). After a closed gate it is the only branch a run follows:
  left empty, the run ends as failed.
- **Always**, when set, is taken instead of the other two, except after a
  closed gate. Approval steps have no Always branch; one that an older
  version saved is taken only on approval.

A branch is empty, ends the workflow, or names a step of the workflow, an
earlier one too. An empty branch goes on with the next step in the list;
after the last step the run ends, and succeeds or fails with that step. The
steps table names where each branch leads:

| Label | Meaning |
|---|---|
| `→ 5. Rollback` | The branch names step 5. |
| `→ 3. Deploy (next)` | The branch is empty: `This branch is empty: the run goes on with the next step in the list, so where it leads changes when steps move.` |
| `→ 3. Deploy (next), also after a failure` | A job step's empty On Failure that leads where On Success leads: the run goes on also when the job failed. |
| `↺ 3. Deploy` | A branch back to the step itself or an earlier one: `Leads back to this step or an earlier one: the run repeats steps until a branch leads elsewhere.` |
| `end · run succeeds` | The run ends here and succeeds. |
| `end · run fails` | The run ends here and fails. |
| `end · run succeeds or fails with this step` | A job step's Always branch that ends the workflow: the run ends with the step's own outcome. |
| `missing step (ID 7) · run fails` | `The step this branch leads to no longer exists: the run fails here. Edit the step to choose another one.` |
| `—` | The branch is not used: `Not used: the Always branch is taken instead.`, `Not used: a pause step does not fail; Resume continues with On Success.` or `Not used: this step cannot run, so a run fails when it reaches it.` An empty Always reads `—` too. |

A gated job step's On Failure has two parts when they lead to different
places, a job that ran and failed and a closed gate, such as
`→ 4. Verify (next), also after a failed job; end · run fails after a refused approval`
(`A refused approval was rejected, timed out or canceled: the job never ran, only On Failure is followed, and Always is not.`).
An approval step's On Failure is its closed gate: empty and an end both
read `end · run fails`. The Always column adds when a step takes it:
`(only on approval)` for an approval step, `(on Resume)` for a pause step,
`(not after a rejected, timed-out or canceled approval)` for a gated job
step.

For example, the workflow "Release web" with these steps:

| # | Name | Type | On Success | On Failure | Always |
|---|---|---|---|---|---|
| 1 | Build | Job | `→ 2. Release approval (next)` | `end · run fails` | `—` |
| 2 | Release approval | Approval | `→ 3. Deploy (next)` | `end · run fails` | `—` |
| 3 | Deploy | Job | `→ 4. Verify (next)` | `→ 5. Rollback` | `—` |
| 4 | Verify | Job | `end · run succeeds` | `↺ 3. Deploy` | `—` |
| 5 | Rollback | Job | `end · run succeeds` | `end · run fails` | `—` |

A run builds, waits for the release approval, deploys and verifies. A
failed deploy goes to Rollback, and a failed verify deploys again. A loop is
allowed and not capped: this run deploys and verifies again and again until
Verify succeeds, Deploy fails or someone cancels the run, and a job step
whose On Failure names itself runs again until it succeeds or the run is
canceled. Give every loop a way out.

## Moving steps

▲ and ▼ in a step's row move it one place (`Move step 3 "Deploy" up`);
▲ is disabled on the first row, ▼ on the last. **Move to…** moves a step
in one click: `To the start`, `After 1. Build` or `To the end`, leaving out
the places where the step already is. Each choice shows first what the move
would change: that runs will start with another step, that runs will reach
a job step without passing the approval step they pass now, or
`The approval step "Release approval" will not be on the success path: runs whose steps all succeed will not wait for it.`
On a workflow of more than 20 steps, **Move to…** opens the page of the
step at its Position field instead
(`Choose the place on the page of the step: its Position field says what each place would change.`);
▲ and ▼ stay.

The warnings follow the branches, not the positions alone. A job step is
behind an approval step when runs reach it, and every way they reach it
passes an approved approval step. A change that lets runs reach such a job
step without one names it, also a job step that a branch of the moved step
leads to. For Deploy of "Release web", `To the start` shows
`Runs will start with "Deploy" instead of "Build".`,
`Job step "Deploy" will run before the approval step "Release approval", without waiting for it.`
and
`Job step "Rollback" will run before the approval step "Release approval", without waiting for it.`,
as a failed deploy goes to Rollback.

A move changes more than the step's place. Runs start with whatever is
step 1, every empty branch goes on with whatever step now follows, and the
last step ends the run with its outcome. A job step's variable mapping
reads the output of the step that led to it, so a move can change which
step's output it gets. The flash says what changed, for example after
moving Deploy to the start of "Release web":
`Step "Deploy" moved to position 1 of 5, at the start. "Deploy" now continues with "Build" (was "Verify"). "Release approval" now continues with "Verify" (was "Deploy").`
followed by one warning per effect:
`Runs now start with "Deploy" (was "Build").`,
`Job step "Deploy" now runs before the approval step "Release approval": runs start it without waiting for that approval.`
and
`Job step "Rollback" now runs before the approval step "Release approval": runs start it without waiting for that approval.`
An approval step that is off the success path after a change that added,
moved or edited it, or that a change takes off the success path, adds
`The approval step "Release approval" is not on the success path: runs whose steps all succeed do not wait for it.`
A move that leaves the step where it was reads `Step "Deploy" stays at position 3.`;
a refused move says why, after `Step not moved: `. Each move is audited as
`workflow-template.step.moved`.

## Editing a step

**Edit** in a step's row opens its own page, titled like
`Edit step 4 "Verify"`. The step's name, its job template or approval rule,
its branches and its position (`Keep at position 4` by default) can change
there; **Save Step** saves them. Branches can lead to any step, a later one
too, or to the step itself (`4. Verify (this step)`). The step keeps its ID,
so the branches that lead to it and the history of its runs stay.

- The type is fixed:
  `A step's type cannot change: add a new step and remove this one.`
- The Position field says where the step will run as on Add Step. Where
  the step's own On Success or Always leads elsewhere, it names only the
  step before it, `Runs after "Build".`, or at the start `Runs first.` For
  an approval step, each place where it would not be on the success path
  warns
  `This approval step will not be on the success path: runs whose steps all succeed will not wait for it.`;
  a place that would take another approval step off it warns as
  **Move to…** does.
- `Steps that route here` lists the other steps whose branches name this
  one, by position, or says `No other step routes here.` They are exactly
  the steps that keep it from being removed (see
  [Removing a step](#removing-a-step)). Under a step whose branch here no
  run takes, a note says why: `Not used: its Always branch is taken instead.`,
  `Not used: a pause step does not fail. Saving that step clears its On Failure.`,
  `Not used: that step cannot run.` or
  `Not used: that step has an unknown type and cannot be saved. Remove it first.`
- A step's variable mapping is shown read-only
  (`Variable mapping (change it through the API):`). A step without one
  shows none, also one whose mapping a version before 2.11.0 stored as
  `[]` or `{}`.
- A step whose job template was deleted says
  `The job template of this step no longer exists: choose another one.`
- An Always branch that an older version saved on an approval or a pause
  step shows under On Success:
  `This step has an Always branch from an older version, which runs follow when it succeeds. It shows under On Success now and is saved there.`
- While a run of the workflow is active the form is disabled (see
  [Removing a step](#removing-a-step) for the message).

The flash names what changed, such as
`Step "Verify" saved: On Failure changed.`; a save that changes nothing
reads `Step "Verify" is unchanged.` A refused save keeps what was typed and
says why, after `Step not saved: `. Each edit is audited as
`workflow-template.step.updated`, with the fields that changed; a variable
mapping is recorded as changed, never with its values.

## Removing a step

**Remove** asks `Remove step 4 "Verify"?` first. The flash says where the
step was and how the empty branches of other steps now lead, such as
`Step "Verify" removed; it was at position 4 of 5. "Deploy" now continues with "Rollback" (was "Verify").`
Removing the last approval step of a workflow warns, for each job step that
runs reached only through it,
`Job step "Deploy" no longer comes after an approval step: runs start it without waiting for an approval.`
Each removal is audited as `workflow-template.step.removed`.

A step that other steps name in a branch is not removed:
`Step "Rollback" was not removed: other steps route to it (step 3 "Deploy"). Edit those steps to lead elsewhere first, then remove it.`
Edit the steps it names, choose another step or an end for their branch,
then remove it.

No step is added, edited, moved or removed while a run of the workflow is
active:
`Steps cannot change while run(s) #12 of this workflow are active: wait for them to finish or cancel them.`
A run reads the steps at every step it reaches, so a change could send it
elsewhere than it started. The page names the active runs and hides the
step controls until they end.

## Running a workflow

**Launch** on the workflow's page starts a run. The flash says how it
started: `Workflow "Release web" launched.`, or, when the start step waits
for an approval or is a pause step,
`Workflow "Hotfix" launched. Step "Release approval" waits for approval (Approval Request #18).`
or `Workflow "Hotfix" launched. It is paused at step "Wait".` A system
outside Ansilume launches a run through the workflow's trigger:
`POST /trigger/fire-workflow` with the trigger token in the
`X-Trigger-Token` header, which runs as the user who generated the token.
The extra vars of a launch reach every job step: a job step gets its job
template's extra vars, then the launch extra vars, then its variable
mapping of the output of the step that led to it. Entries of a mapping that
do not map an extra var name to the name of an output variable, which an
older version could store, map nothing.

The run's page lists the steps it executed, in the order they ran, with
the columns `#` (`Order in this run`), Step, Type, Status, Job / Request,
Started, Duration and Finished. Durations read `45s`, `1m 15s`, `2h 13m` or
`3d 4h`, prefixed `running ` while a step runs and `waiting ` while it
waits; times read `H:i:s` for today and `Y-m-d H:i:s` for other days. The
page follows the run without a reload: **Resume** appears when the run
pauses, and **Cancel** and **Resume** go away when the run ends. It asks
for news 3 seconds after something changed, less and less often while
nothing does, at most 30 seconds apart, and not while its tab is hidden;
shown again, it asks at once. When it may no longer follow the run,
because the user signed out or lost access, or the run was deleted, the
Live badge gives way to
`Live updates stopped. Reload the page to keep following the run.`; the
page of a job says
`Live updates stopped. Reload the page to keep following the job.`
A job's page asks every 2.5 seconds while the job runs; while the job
waits for an approval, to be queued or for a runner, it asks less and less
often while nothing changes, from 2.5 up to 30 seconds apart, and it does
not ask while its tab is hidden either.
**Cancel** ends the run: its step's job is canceled with its approval
request, and the step reads Canceled.

## What a run waits for

A run is stored as `running` while it waits, and so is the step it waits
at. The pages show what it waits for instead, as derived states:

| Shown | Stored status | `waiting_for` | When |
|---|---|---|---|
| Awaiting Approval | `running` | `approval` | An approval step waits for its approval request, or a gated job step's job waits for approval. |
| Paused | `running` | `resume` | A pause step waits for **Resume**. |
| Queued | `running` | `runner` | A job step's job waits for a runner. |
| Running | `running` | none | Any other step that runs. |
| Canceled | `failed` | none | A step that a cancel of its run, or the deletion of its workflow template, ended. |

A finished run shows its stored status: Succeeded, Failed or Canceled.

The run's page puts a banner above the steps while the run waits. Its title
says what it waits for:

- `Waiting for approval at step "Release approval".`
- `Job #34 of step "Deploy" waits for approval.`
- `Paused at step "Wait" until someone resumes the run.`
- `Job #34 of step "Deploy" is queued: it waits for a runner.`

For an approval, the banner lists the request (`Approval Request #18`, a
link for users with `approval.view`), its rule (`Rule "deploy-prod"`, or
`Rule deleted`), `1 of 2 approval(s)`, `2 approver(s) can still decide`,
`waiting since 14:02:11` and when it expires: `never expires`,
`expires 2026-10-14 14:02:11 (then rejected)` or, once that time has passed,
`expired 2026-10-14 14:02:11; the next maintenance run applies its timeout (then rejected)`.
It never names the approvers. A request that no longer exists reads
`Its approval request no longer exists: the next maintenance run cancels the wait, and the step fails.`
One line says what it means for you:

- `You can decide on this request.`, with the button `Review & decide`,
  which opens the request;
- `You launched this run, so another approver must decide.`
- `You have voted on this request; it waits for more approvals.`
- `You are not an approver of this request.`
- `You are on the rule's approver list, but may not decide on this request.`

When too few eligible approvers are left to reach the rule's required
approvals, the banner warns
`Too few of the rule's approvers can still decide, so this request can no longer be approved. An admin can add approvers to the rule, or cancel the run.`

## Deciding on an approval

Approvers decide on the page of the request: **Review & decide** on the run
page and **Review** on the dashboard lead there. A user may decide when they
are on the rule's approver list, are active, hold `approval.decide` (or are
a superadmin) and may see the job. The approver list of a rule names users,
the members of a team, or, for a role rule, the users assigned that role
directly: not those who hold it through another role, as admins hold
operator, nor every signed-in user for the default role `viewer`. A team
rule counts every member of the team. The rule's form says so under Role:
`Approvers are the users assigned this role directly, not through another role, nor as the default role every signed-in user holds. Those of them who are active, hold approval.decide (or are superadmins) and may see the job can approve, never the user who launched it.`
and under Team:
`Approvers are the members of this team. Those of them who are active, hold approval.decide (or are superadmins) and may see the job can approve, never the user who launched it.`
The user who launched the run never decides on its requests, admins
included:
`You launched this workflow run; another approver must decide.`
A run that a trigger launched counts as launched by the user who generated
the trigger's token.

Who may approve so follows the users' roles, their team memberships and the
projects of their teams, which an approval rule does not lock: whoever may
change these changes who may approve a request, without
`approval-rule.update`. By default only admins assign roles, change what a
role grants, and manage teams, their members and their projects; with
LDAP, the directory groups of a user give their roles. This is a known
limit of 2.11.0 (see
[updating.md](updating.md#after-updating-from-2101-or-older-workflow-steps-and-waiting-runs)).

Approved, an approval step goes on with its On Success branch, and a gated
job step's job runs. Rejected, timed out or canceled, the gate is closed:
the run follows only On Failure, and ends as failed when it is empty. A
rule's timeout is applied by the maintenance run, every minute. Once
decided, the step's row on the run page says how:
`Approved by alice at 14:05:40.`, `Rejected by bob at 14:05:40.` or
`Approved by its timeout at 14:05:40, without a decision.`, and the comment
of an approval follows as `Comment: "Change window confirmed"`. Users
without `approval.view` read `Approved at 14:05:40.`, without names.

An approval step is backed by a job of its own that never runs and only
holds the request: its page reads
`This job only holds the approval request of step "Release approval" in Workflow Job #12 and never runs: approved, the run goes on; rejected, timed out or canceled, the step fails.`

## Where waiting runs show

- **Workflow Jobs**: the Status column shows the state, the Current Step
  column the step and its request, and the filter reads All, Awaiting
  Approval, Paused and Queued.
- **The workflow's page**: `Active runs` lists each active run
  (`at step "Release approval" since 14:02:11`, `launched by alice`), up to
  ten (`and 3 more`), with the link `All runs of this workflow`; the Type
  column marks the step where runs wait, such as `1 run(s) waiting`.
- **The dashboard**: Running Workflows marks waiting runs with their state
  and links **Review** for a run that waits for an approval; Pending
  Approvals has a For column, and the buttons `All Workflow Jobs` and
  `All pending`.
- **Approval Requests**: the For column names the workflow, the step and the
  run, such as `Release web › Release approval (Workflow Job #12)`, and an
  Expires column; the filter reads All and each status. A gated job step's
  request names the workflow only for users who may view the workflow;
  others read `Deploy web (job template)`.
- **The job page**: a job that waits for approval says
  `This job waits for Approval Request #18.`; the details name the request
  and the run (`#12, step "Deploy"`). The jobs list names an approval
  step's job `Approval step "Release approval" (Workflow Job #12)`.

## A run that seems stuck

Read the banner on the run's page: it says what the run waits for and who
can move it on. A run that waits for a runner needs an online runner in
the job template's runner group; one that waits for an approval nobody can
give needs an admin; a paused run needs **Resume**. The entry
[A workflow run seems stuck](troubleshooting.md#a-workflow-run-seems-stuck)
goes through each case. While a run waits, it locks the workflow's steps.

## Who may do what

The built-in roles hold these permissions; every signed-in user holds the
role `viewer` as well:

| Action | Permission | Viewer | Operator | Admin |
|---|---|---|---|---|
| See workflow templates, their steps and branches | `workflow-template.view` | yes | yes | yes |
| See runs, their states and banners | `workflow.view` | yes | yes | yes |
| See approval requests | `approval.view` | yes | yes | yes |
| Create a workflow template | `workflow-template.create` | no | yes | yes |
| Add, edit, move and remove steps | `workflow-template.update` | no | yes | yes |
| Launch a workflow and resume a paused run | `workflow.launch` | no | yes | yes |
| Cancel a run | `workflow.cancel` | no | yes | yes |
| Approve or reject, as an approver of the rule | `approval.decide` | no | yes | yes |
| Delete a workflow template | `workflow-template.delete` | no | no | yes |
| Manage and assign approval rules | `approval-rule.update` | no | no | yes |

Changing, launching, resuming and canceling a workflow also need operator
access to the project of every job step; seeing it, view access (team
scoping). Approval gates are for admins (`approval-rule.update`): adding an
approval step, giving a job step a job template with an approval rule, also
by editing it, and every change of the steps of a workflow that has an
approval step or a gated job step. Others read
`Only admins may add approval steps: that needs approval-rule.update.`,
`Only admins may add a job step whose job template has an approval rule: that needs approval-rule.update.`,
`This workflow has an approval step: only admins may change its steps (approval-rule.update).`
or
`This workflow has a job step whose job template has an approval rule: only admins may change its steps (approval-rule.update).`
They may still rename such a workflow, launch it and manage its trigger
token.

## API

Everything this guide describes is also in the REST API, under `/api/v1`,
with a token in the `Authorization: Bearer` header. The OpenAPI spec
([web/openapi.yaml](../web/openapi.yaml), served at `/openapi.yaml` and in
the Swagger UI) has every field, answer and example. The examples below use
the workflow "Release web" (ID 7) from
[Branches](#branches-where-a-run-goes-next), with the step IDs 41 to 45.

### Steps

| Endpoint | Does |
|---|---|
| `POST /api/v1/workflow-templates/{id}/steps` | Adds a step (`201`). |
| `PUT /api/v1/workflow-templates/{id}/steps/{stepId}` | Edits a step: fields left out stay, `null` clears a branch (`200`). |
| `POST /api/v1/workflow-templates/{id}/steps/{stepId}/move` | Moves a step (`200`). |
| `DELETE /api/v1/workflow-templates/{id}/steps/{stepId}` | Removes a step (`200`). |

Each needs `workflow-template.update` and operator access to the project of
every job step, and answers like the web UI: the rules above, `409` while a
run is active, and `409` with `error.step_ids` for a step that other steps
route to. A branch is `null` (empty), `0` (end the workflow) or a step ID.
`position` is `"start"`, `"end"` or a number from 1, and `after_step_id` the
ID of the step to place it after; send one of them, never both. Without
either, an add goes to the end and an edit stays where it is. A number is
read when the change is applied, so changes by others in between can shift
it; `after_step_id` cannot. A move takes exactly one of `position`,
`after_step_id` and `direction` (`"up"` or `"down"`).

A step's variable mapping (`extra_vars_template`) is an object of extra
var name to output variable name, such as
`{"app_version": "build_version"}`; `null`, `{}` and `[]` mean none. Other
JSON answers `422`
(`extra_vars_template must be null or a JSON object that maps extra var names to the names of output variables, such as {"app_version": "build_version"}.`).
An edit compares the mapping by its JSON value, so sending a step back as
`GET` answers it changes nothing.

Every answer has the step as it is now (`data`, with its `position`,
`is_start`, `reachable`, `cannot_run` and `routes`) and what the change did
(`change`, with the flash's `message` and `warnings`); a removal answers
`data: {"deleted": true, "id": ...}`. `GET /api/v1/workflow-templates/{id}`
lists the steps by position, with `start_step_id` and `success_path`.

Add "Smoke test" after Deploy:

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"name": "Smoke test", "step_type": "job", "job_template_id": 16, "on_failure_step_id": 0, "after_step_id": 43}' \
     https://ansilume.example.com/api/v1/workflow-templates/7/steps
```

Let Verify roll back instead of deploying again after a failure:

```bash
curl -X PUT -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"on_failure_step_id": 45}' \
     https://ansilume.example.com/api/v1/workflow-templates/7/steps/44
```

Move Rollback to position 2; to the same place by naming the step it goes
after, Build; or one place up:

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"position": 2}' \
     https://ansilume.example.com/api/v1/workflow-templates/7/steps/45/move

curl -X POST -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"after_step_id": 41}' \
     https://ansilume.example.com/api/v1/workflow-templates/7/steps/45/move

curl -X POST -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"direction": "up"}' \
     https://ansilume.example.com/api/v1/workflow-templates/7/steps/45/move
```

Remove Verify:

```bash
curl -X DELETE -H "Authorization: Bearer $TOKEN" \
     https://ansilume.example.com/api/v1/workflow-templates/7/steps/44
```

`PUT /api/v1/workflow-templates/{id}` with a `steps` list still replaces
every step: the steps get new IDs and no branches. Use the step endpoints
to change single steps.

### Runs and approvals

Every run in `GET /api/v1/workflow-jobs`, `GET /api/v1/workflow-jobs/{id}`
and the answers of `cancel` and `resume` has `waiting_for` (`approval`,
`resume`, `runner` or `null`) and `current_step` (`id`,
`workflow_step_id`, `step_name`, `step_type`). Its `status` stays the
stored one: `running` while it waits. Each step of the run's detail has
`waiting_for`, `job_status`, `approval_request_id` and `approval`: the
request's status, rule, counts and expiry, and, for the request the run
waits for, `eligible_count`, `open_count` and `can_still_be_approved`. The
launch of a workflow answers `workflow_job_id`, `waiting_for` and
`approval_request_id`.

Runs that wait for an approval, and the pending approval requests:

```bash
curl -H "Authorization: Bearer $TOKEN" \
     "https://ansilume.example.com/api/v1/workflow-jobs?waiting_for=approval"

curl -H "Authorization: Bearer $TOKEN" \
     "https://ansilume.example.com/api/v1/approvals?status=pending"
```

`waiting_for`, `workflow_template_id` and `status` filter the runs, and
combine; `status` filters the approval requests. A value the list does not
know answers `422`, such as
`waiting_for must be one of: approval, resume, runner.` or
`status must be one of: pending, approved, rejected, timed_out, canceled.`
Every approval request has `workflow_job_id`, `workflow_job_step_id`,
`workflow_template_id` and `workflow_step_name`: `null` for a request
outside a workflow, and for callers who may not view its workflow. Every
approval request also answers `decisions`: every vote on it in the order
cast, with `user_id`, `username`, `decision`, `comment` (`null` without one)
and `created_at`. These are the votes behind a step's
`Approved by alice at 14:05:40.` and its
`Comment: "Change window confirmed"` on the run page; a vote of the
launcher, cast before self-approval was refused, is listed but counts for
nothing. `decisions` is `null` for callers without `approval.view`. Approve
and reject answer `403`
`You launched this workflow run; another approver must decide.` for the
run's launcher.

A job's detail names its request and its run:

```bash
curl -H "Authorization: Bearer $TOKEN" \
     https://ansilume.example.com/api/v1/jobs/34
```

answers, among the job's fields, `approval_request_id`, `workflow_job_id`
and `workflow_job_step_id`; an approval step's job has no
`job_template_id`. Three things differ from the web UI on purpose:

- The API has no derived step state: a step that a cancel of its run, or
  the deletion of its workflow template, ended keeps the status `failed`,
  with the `error` `Not finished: the run was canceled.` or
  `Not finished: the run was canceled because its workflow template was deleted.`
- A run's detail says nothing about the caller's own relation to a request
  (may decide, launched the run, voted), and nothing about whether they may
  resume or cancel; the `approval_request_id` and `approval` of a step are
  `null` where the caller may not see the request.
- The items of `GET /api/v1/jobs` carry none of the three link fields:
  read them from `GET /api/v1/jobs/{id}`.
