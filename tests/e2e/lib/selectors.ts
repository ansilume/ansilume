/** Centralized CSS selectors for the Ansilume UI. */

// Layout
export const SIDEBAR = '#sidebar';
export const SIDEBAR_LINK = (text: string) => `#sidebar a:has-text("${text}")`;
export const MAIN_CONTENT = '.main-content';
export const PAGE_TITLE = 'h1, h2';

// Flash messages
export const FLASH_SUCCESS = '.alert-success';
export const FLASH_DANGER = '.alert-danger, .alert-error';
export const FLASH_WARNING = '.alert-warning';
export const FLASH_INFO = '.alert-info';

// Login form
export const LOGIN_USERNAME = '#loginform-username';
export const LOGIN_PASSWORD = '#loginform-password';
export const LOGIN_SUBMIT = '#page-content button[type="submit"], form[id*="login"] button[type="submit"]';

// Generic forms — scoped to page content so we never click the sidebar logout button.
export const FORM_SUBMIT = '#page-content button[type="submit"]';
export const FORM_ERROR = '.invalid-feedback, .help-block-error, .has-error .help-block';

// Tables (Yii2 GridView)
export const TABLE = 'table.table';
export const TABLE_ROW = 'table.table tbody tr';
export const TABLE_EMPTY = '.empty';

// Action buttons
export const BTN_CREATE = 'a:has-text("Create"), a:has-text("Add")';
export const BTN_UPDATE = 'a:has-text("Update"), a:has-text("Edit")';
export const BTN_DELETE = 'button:has-text("Delete"), a:has-text("Delete")';
export const BTN_VIEW = 'a:has-text("View")';

// Confirm dialog
export const CONFIRM_OK = '.modal .btn-danger, .modal .btn-primary';
