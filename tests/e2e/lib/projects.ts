import { Page } from '@playwright/test';
import { notAnInteger } from './helpers';
import { idWhere, indexRows } from './scoping';

/** Helpers for the project pages (views/project/). */

/**
 * models/Project.php: the integer rule's message for a repository credential
 * that is no credential ID, such as an empty list (the form shows it under
 * the Credential field, and above the form while it hides the Git fields).
 */
export const NOT_A_CREDENTIAL_ID = notAnInteger('Scm Credential Id');

/** The ID of the project `name`, from the project list (columns: #, Name, ...). */
export async function projectId(page: Page, name: string): Promise<string> {
  return idWhere(await indexRows(page, '/project/index'), 1, name);
}

/**
 * The project form's fields as JSON text for a new git project `name` whose
 * repository credential is the JSON text `credential`, such as '[]'. Its
 * repository URL leads where nothing listens, so the sync of such a project,
 * should one be saved, fails at once.
 */
export function gitProjectWithCredential(name: string, credential: string): string {
  return `{"Project": {"name": "${name}", "scm_type": "git", "scm_url": "https://127.0.0.1:1/org/e2e-json.git", `
    + `"scm_branch": "main", "scm_credential_id": ${credential}}}`;
}
