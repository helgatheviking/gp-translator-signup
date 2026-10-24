# Translator Sign-up for GlotPress

Lets translators sign up for a GlotPress site on their own, with the languages they can translate, and lets you approve them as reviewers. No form plugin needed: it extends WordPress's own registration page.

## How it works

1. **Sign-up.** Translators register at `wp-login.php?action=register`. The page gains a checkbox for each language that has a translation set, an optional "Other languages" field, and a hidden honeypot field that blocks spam bots. A "Sign up to translate" link appears in the GlotPress header while registration is open.
2. **Pending.** New users are created with the default role (set it to Subscriber) and marked **Pending**. Like any logged-in user, they can suggest translations straight away, but can't approve them. WordPress's new-user email to the admin lists their languages and links to the pending list.
3. **Approved.** Approving a translator lets them approve translations in their languages, on every project, including projects added later. They get an email saying so.
4. **Rejected.** Rejected translators can no longer suggest translations.

Translators can't change their own languages; only admins can, from the user's profile.

## Setup

1. Activate the plugin (GlotPress must be active).
2. In **Settings > General**, tick **Anyone can register** and set **New User Default Role** to Subscriber.

## Reviewing translators

* **Users** has a **Pending translators** view, a **Translator** column with each user's status and languages, and **Approve** / **Reject** links under each translator.
* A user's profile has a **Translator** section for changing their status and languages.

Approved translators' review rights come from this plugin, not from GlotPress's permission records, so they don't appear on GlotPress's **Permissions** screens. Manage them from **Users**.

## Development

```bash
composer install   # also prefixes git-updater-lite into vendor-prefixed/ with Strauss
npm install
composer lint
```

## Releasing

```bash
npm version patch   # or minor / major
```

This bumps the version in `package.json` and the plugin header, commits, tags, and pushes. The tag starts the GitHub Actions release workflow, which builds the zip and attaches it to a GitHub release. The release notes are the commit subjects since the previous tag, so write commit subjects as changelog lines.

Updates are delivered with [Git Updater Lite](https://github.com/afragen/git-updater-lite), from the update server in the plugin's `Update URI` header.
