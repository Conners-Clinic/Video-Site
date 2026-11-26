# Confirmed code defects in the inspected PlayTube installation

**Reviewed:** 11 November 2026  
**Application identification:** PlayTube 3.0.1, with local modifications and integrations.  
**Scope:** application code and bundled library defects verified in this installation. Paths below are relative to the application root.

This is a factual defect record, not a general hardening checklist. It excludes host administration, attacker identities, speculative scan alerts, and advice unrelated to a demonstrated code issue. A finding in this customized installation is not a claim that every PlayTube release, or an unmodified upstream release, contains the same defect. Historical exploitation and code-level reproducibility are separate matters.

## 1. Unverified mobile social identity authenticated an existing account

**Files:** `app_api/v1.0/platform/mobile/social_login.php`, `api.php`.

**Defect:** the `wowonder` branch accepted an encoded identity assertion without verifying that the provider authenticated the caller as that identity. An existing email address could be used to obtain an application session.

**Impact:** account impersonation, including impersonation of an administrator when that administrator's identity was asserted. In the investigated incident, retained requests, a malicious configuration value, and matching shell bytes supported a subsequent administrative-settings-to-command-execution chain.

**Applied repair:** the unsafe mobile social-login route is denied before normal application execution. The underlying handler also fails closed. It was not reopened on the basis of a caller-supplied email or encoded profile.

## 2. Configurable FFmpeg values crossed a shell-execution boundary

**Files:** `ajax/ap.php`, `ajax/admin.php`, `assets/includes/functions_one.php`.

**Defect:** settings accepted a caller-controlled `ffmpeg_binary_file`; the administrative FFmpeg diagnostic route interpolated the binary setting and preset into `shell_exec`.

**Impact:** an attacker with access to the affected administrative action could execute operating-system commands with the PHP process's privileges. A recovered setting decoded and wrote a PHP shell in the investigated incident.

**Applied repair:** browser changes to the executable setting are refused, configuration loading pins the executable to `/usr/bin/ffmpeg`, the conversion preset is allowlisted, and the diagnostic execution route remains denied.

## 3. Other FFmpeg commands used unquoted file arguments

**Files:** `cronjob.php`, `ajax/upload-video-ffmpeg.php`, `ajax/ffmpeg-submit.php`, `assets/includes/functions_general.php::ffmpeg_duration()`.

**Defect:** additional commands inserted input/output paths or duration arguments into shell strings without separately quoting the arguments. The scheduled conversion handler consumed database `video_location` values and used an insufficiently constrained path for cleanup.

**Impact:** shell metacharacters in a reachable value could change the command; an outside path could cross the intended cleanup boundary. Ordinary uploads generate constrained filenames, so this finding does not establish an unauthenticated exploit of every listed call.

**Applied repair:** dynamic arguments are quoted individually. Queued inputs resolve to existing regular files inside the site's `upload/videos` directory; traversal, wrappers, outside paths and symlink escapes are rejected. Cleanup receives the validated local path. GIF/preview duration arguments and the shared duration probe were also repaired.

**Verification:** 132 behavioral command/path cases passed against the conversion changes, including hostile argument values and traversal/symlink cases. Tests used a harmless argument recorder rather than executing a malicious payload or converting production media.

## 4. API method names were interpreted as filesystem paths

**Files:** `api.php`, `app_api/v1.0/api-v1.0.php`.

**Defect:** the request's `type` value was concatenated into the API module filename and checked with `file_exists`, without an identifier-only constraint or resolved-directory boundary.

**Impact:** a value containing path components could select an unintended PHP file for inclusion. The API server key did not make a filesystem path a safe method identifier.

**Applied repair:** method names are restricted to a bounded alphanumeric/underscore/hyphen identifier, and the resolved module must remain in the expected mobile API directory.

## 5. Request-supplied ID/category lists entered SQL expressions

**Files:** `ajax/load-more.php`, `sources/search/content.php`, `app_api/v1.0/platform/mobile/popular_channels.php`.

**Defect:** request values were used as comma-separated lists or joined directly into `IN`/`NOT IN` expressions. In some branches, `PT_Secure` was applied to values later used in an unquoted numeric SQL context. HTML transformation is not a numeric SQL boundary.

**Examples of the defective construction:**

```php
$ids = implode(',', $_POST['channels_ids']);
// Later concatenated into: ... user_id NOT IN ( ... )
```

```php
$cat_id = "'" . implode("','", $_POST['category']) . "'";
```

**Impact:** SQL syntax could enter the query through parameters intended to contain only identifiers. Public search/listing code was affected; possession of an ordinary session token was not administrative authorization.

**Applied repair:** identifier arrays/CSV values are normalized through strict numeric-list validation with a cardinality bound. Numeric count parameters are validated separately. Malformed values stop before query execution.

**Verification:** hostile SQL-list input was rejected by actual endpoint guard fixtures before database access; legitimate numeric arrays and explicitly supported CSV inputs were retained.

## 6. Chat deletion concatenated an unvalidated request ID into SQL

**File:** `ajax/messages.php`, `delete_chat` action.

**Defect:** a posted ID entered a raw `WHERE`/`DELETE` expression without a positive-integer boundary and bound parameter handling.

**Impact:** request data could affect SQL structure rather than merely identify the conversation participant.

**Applied repair:** the ID is validated as a positive integer and used in a bound predicate associated with the authenticated conversation actor.

## 7. Actor/target confusion allowed unauthorized account actions

**Files:** `app_api/v1.0/platform/mobile/delete_user.php`, `ajax/user.php`, `ajax/settings.php`.

**Defects:**

- The mobile deletion handler replaced the authenticated user variable with the requested target and compared that target ID to the same requested ID. This was a tautology, not an ownership check.
- The privacy action updated a posted `user_id` without an ownership check.
- Some profile/avatar operations could perform side effects before denying a foreign target.
- The settings handler accepted a `verified` badge update without the required administrator boundary.

**Impact:** cross-account mutation or deletion under the remaining conditions, and unauthorized privilege-related profile changes. The deletion finding does not imply that every request could delete an account without satisfying the handler's other checks.

**Applied repair:** the authenticated actor remains distinct from the target; owner/administrator authorization precedes side effects. Password confirmation is tied to the actor. Verified-badge changes require administrator privilege. Intentional actions involving another person, such as block/notification preferences, retain their separate semantics.

## 8. Delegated roles reached system/privilege-changing administrative actions

**Files:** `ajax/ap.php`, shared `assets/includes/admin_security.php`.

**Defect:** the common entry check admitted moderator/editor roles, but actions did not consistently enforce their corresponding page grants. A short critical-action restriction left other branches reachable by direct requests, bypassing the intended UI permission boundary.

**Impact:** a delegated content-management role could reach administrative operations outside that role's intended authority.

**Applied repair:** role 1 retains owner administration. Roles 2/3 must have the specific constant page permission for one of eighteen reviewed content actions. Settings, system, payment, translation, file, import and unreviewed actions require role 1. Shared video-deletion actions inspect stored target types: a movie-only grant cannot delete an ordinary video, including through a mixed bulk request. The gate covers every backend branch after CSRF validation. A valid request token does not substitute for action authorization.

**Verification:** 412 permission fixtures passed against staged and deployed code across both sites; four final PHP syntax checks passed. The existing CSRF and article-sanitization code remained intact.

## 9. Administrative CSRF protection was inconsistent and tokens were weak

**Files:** `ajax.php`, `ajax/ap.php`, `ajax/admin.php`, `admin-panel/autoload.php`, `assets/includes/functions_general.php::PT_CreateSession()`, `assets/includes/functions_one.php::PT_CreateMainSession()`.

**Defect:** the `ap` route bypassed the common main-hash check, and its handlers did not consistently validate an administrative token. Tokens were derived from `rand(1111, 9999)`, providing only a small set of possible secret inputs despite their hashed appearance.

**Impact:** cross-site requests could reach actions in a logged-in administrative session; the apparent hash length overstated token unpredictability.

**Applied repair:** tokens use `random_bytes(32)`, strict string/length checks and `hash_equals`. Every affected administrative action, including legacy GET actions, validates a session token. The administrative frontend attaches the token only to same-origin requests.

**Verification:** missing/array/incorrect tokens and delegated privilege changes are rejected in actual-handler fixtures. Correct tokens retain the supported request path.

## 10. Deployment actions and diagnostics had insufficient access boundaries

**Files:** `merge.php`, `db.php`, `system-status.php`.

**Defect:** deployment/database tooling used a nonempty administrative flag instead of the strict administrator role; deployment mutations lacked a CSRF check. System diagnostics lacked an explicit administrator gate.

**Impact:** delegated accounts or cross-site requests could reach stronger deployment capabilities; diagnostics exposed internal runtime information.

**Applied repair:** strict administrator authorization and deployment CSRF enforcement were added. The deployment caller sends a session-bound token, and diagnostics deny non-administrators.

## 11. Password changes created weak hashes or transformed passwords

**Files:** `ajax/settings.php`, affected web/mobile login, registration, password-change and account-deletion handlers.

**Defect:** some settings code still wrote SHA-1 passwords. Other flows mixed raw passwords with SQL/HTML normalization before hashing or verification. This created weak stored credentials and inconsistent handling of special-character passwords.

**Applied repair:** new password writes use `password_hash(..., PASSWORD_DEFAULT)` on raw input. Modern verification uses `password_verify`; a narrowly defined legacy SHA-1 path supports migration after successful proof. Existing bearer sessions are revoked after a password change/reset. Newly created provider-only accounts receive an unknown random secret rather than a predictable email/time-derived password. Existing `SHA1(email)` credentials and reliably identified legacy provider-generated SHA-1 credentials are refused during password login; modern reset passwords remain valid. This repair does not rewrite existing account records.

**Verification:** modern special-character passwords, legacy migration, incorrect passwords, owner/admin boundaries and failed session writes are covered by behavioral fixtures.

## 12. Session and recovery secrets used predictable generation

**Files:** affected web/mobile/provider authentication handlers; `assets/includes/functions_general.php::PT_GenerateKey()`; `assets/includes/functions_one.php::createBackupCodes()`.

**Defect:** session identifiers and recovery codes used `rand`, time, `microtime`, or hashes of those values. Hashing predictable inputs does not make them cryptographically random.

**Applied repair:** owned bearer-session flows use 32 random bytes encoded as hex; filename/key generation uses cryptographic randomness; new backup codes use cryptographically random hex strings. PHP session identifiers regenerate when authentication or password-approved pending authentication is established.

## 13. Two-factor completion was not reliably bound to a password-approved challenge

**Files:** `sources/login/content.php`, `sources/two_factor_submit/content.php`, mobile `login.php`, `two-factor.php`, and `tv.php`.

**Defects:**

- The web submit path trusted a writable username cookie rather than server-side proof of a preceding password check.
- A generic `email_code` comparison lacked a consistent purpose/expiry/attempt/single-use boundary.
- Some comparisons used loose equality.
- Enabled non-email second factors could be skipped when the global email-two-factor feature was off.

**Impact:** password/second-factor bypass, replay or excessive guessing under the affected flow's conditions.

**Applied repair:** server-side pending authentication is bound to the user/method and a password-approved step. Email challenges have purpose, issue time, five-minute lifetime, capped attempts and compare-and-swap consumption. Backup codes compare strictly and are consumed once. Enabled factors are not silently skipped. Unsupported mobile non-email completion fails closed instead of granting a password-only session.

## 14. Password-reset validation accepted a shared verification field

**Files:** `sources/forgot_password/content.php`, `sources/reset-password/content.php`, mobile `reset_password.php`, related registration/resend code.

**Defect:** a generic database `email_code` served multiple credential purposes. The reset path did not adequately distinguish a reset grant from registration/OTP data, and did not enforce the repaired purpose/expiry/atomic-consumption boundary. It also automatically created a session after reset, bypassing an enabled local second factor.

**Applied repair:** reset-specific random tokens carry a purpose marker and issue time, expire after one hour and are atomically consumed with the password change. Registration/resend tokens use a separate format. Old bearer sessions are revoked. Successful reset returns to the normal login/second-factor path rather than creating an immediate authenticated bearer.

## 15. OAuth account linking trusted email matching without a stored subject binding

**Files:** `ajax/google_login.php`, `social-login.php`, `wo_login.php`.

**Defects:**

- The AJAX Google path used `kid` as identity rather than a verified provider `sub`, and lacked sufficient audience/issuer/expiry/verified-email checks.
- Matching an existing account by returned email was treated as enough to link/authenticate it without proving a stored provider-subject binding.
- The WoWonder callback lacked a reliable matching OAuth state boundary in the inspected flow.

**Applied repair:** the Google verifier uses fixed HTTPS token validation with strict TLS and validates audience, issuer, expiry, subject and verified email. Existing accounts require the actual stored Google subject. Unbound generic email collisions do not authenticate an existing local account. Missing/mismatched WoWonder state fails closed. Local second-factor requirements remain effective.

**Reference:** [Google's ID-token validation requirements](https://developers.google.com/identity/gsi/web/guides/verify-google-id-token).

## 16. Browser authentication handlers lacked complete CSRF/origin enforcement

**Files:** affected `sources/*/content.php` authentication handlers and `themes/*/layout/auth/` POST forms.

**Defect:** AJAX token checks did not protect separate server-rendered POST handlers. The source forms/handlers did not consistently provide and enforce their own browser request boundary.

**Applied repair:** paired forms carry a server-generated token. Source handlers reject cross-origin/null-origin requests and require an exact same-origin Origin/Referer or valid token fallback. API authentication retains its separate credential boundary.

## 17. Authentication cookies and redirects crossed unsafe boundaries

**Files:** affected authentication handlers; `assets/includes/app_start.php`.

**Defects:**

- Bearer cookies were issued without consistent Secure/HttpOnly/SameSite flags and commonly persisted for ten years.
- The application renewed cookies using those legacy attributes even after a handler issued a safer cookie.
- A `cookie` query parameter could establish browser login state, exposing credentials to URLs and enabling forced browser login.
- Some authentication redirects accepted external request targets or Referer values.
- An inactive-account redirect did not stop subsequent authenticated application execution.

**Applied repair:** issuance and renewal use secure cookie flags and bounded persistence; URL-based browser login is denied; authentication redirects remain on the configured origin; inactive-account handling exits before further authenticated execution.

## 18. Image uploads trusted file names and client MIME claims

**Files:** `assets/includes/functions_one.php::PT_ShareFile()` and `PT_UploadLogo()`; resumed chunk-upload handling in `assets/includes/functions_general.php`.

**Defects:** image extension/client MIME checks did not establish that uploaded bytes were a valid image without executable PHP. Resumed chunk names came from database state without an equivalent filename/path boundary. In `PT_ShareFile`, content validation inspected `$data['file']` while chunk assembly consumed `$_FILES['video']['tmp_name']`; the two could differ. A valid image in the checked input therefore did not establish the safety of the bytes being written.

**Observed evidence:** three image-named files in the inspected upload tree were actually obfuscated PHP webshells. Their original upload route is unknown; this evidence does not prove they were uploaded through a specific application function.

**Applied repair:** image content is checked against the expected image type, dimensions/pixel count and size bounds. Embedded PHP is checked without executing uploaded code. Short echo tags require syntax-aware handling because compressed image bytes can coincidentally contain the same substring. Resumed names and upload directories are constrained before writes. Chunked image requests stop before database/directory writes; video chunks require an exact match to the validated multipart temporary path and integer `UPLOAD_ERR_OK`. Ordinary nonchunked images and videos remain supported.

**Verification:** 209 upload-validation cases passed, including ordinary PNG/GIF content, compressed-image short-tag coincidences, the preserved shell samples, genuine PHP/image polyglots, quoted-close-tag handling and chunk-boundary cases. PHP syntax probes use lint-only operation, fixed arguments and resource/time bounds. Fifteen additional fixtures exercised the actual `PT_ShareFile` source: the original mismatched input reached chunk assembly, while the fixed function rejected it with zero database/write calls. These binding fixtures used controlled mutation stubs, not live uploads or database writes.

## 19. Remote fetch helpers disabled TLS verification and accepted unsafe destinations

**Files:** `assets/includes/functions_general.php::connect_to_url()` / `connect_to_url_headers()`, `assets/includes/functions_one.php::fetchDataFromURL()`, affected media fetchers and payment request helpers.

**Defect:** several helpers explicitly disabled certificate/hostname checks. Untrusted media destinations lacked a consistent public-address/protocol/redirect boundary.

**Impact:** interception of sensitive API/provider traffic where TLS verification was disabled; server-side requests to unintended destinations where the caller controlled the URL.

**Applied repair:** TLS verification is enabled. Shared untrusted fetch paths validate public destinations, reject credentials/non-HTTP protocols/private/reserved addresses, pin DNS for the connection, recheck redirects and enforce time/body limits. Fixed-provider identity requests use their own verified provider boundary.

**Verification:** regression cases cover loopback, private/shared/reserved/multicast, mapped IPv6, dangerous protocols, URL credentials and unauthorized ports. A false-positive or failed fetch is not treated as successful provider verification.

## 20. Paid API actions credited a ledger without verified payment

**Files:** mobile `buy_video.php`, `rent_video.php`, `subscribe_to_channel.php`.

**Defect:** affected paid paths could create purchase/subscription records and credit an owner's balance from posted video/channel parameters without an atomic corresponding wallet debit or verified external payment receipt. Repeated calls could repeat the credit/access effect.

**Impact:** unauthorized paid access and artificial balance/ledger credit.

**Applied repair:** the unverified paid creation paths fail closed. Ordinary free subscription/unsubscription remains separate. No replacement payment protocol was invented or represented as verified checkout.

## 21. Paid-history pagination used the wrong ownership predicate

**File:** mobile `paid_videos.php`.

**Defect:** pagination used `paid_id >= authenticated_id` where the initial ownership query required equality.

**Impact:** paginated results could include another purchaser's rows.

**Applied repair:** the ownership predicate remains equality for paginated requests as well as the initial request.

## 22. Full media/caption responses bypassed content-access rules

**Files:** mobile `get_video_details.php`, `sources/vtt/content.php`, `sources/embed/content.php`, new shared `assets/includes/video_security.php`.

**Defect:** the full video object/media response, transcript route and main/playlist embed responses did not consistently apply the same privacy/payment/access conditions as viewing restricted content. The VTT handler also concatenated a database path into a local filename without the repaired canonical boundary.

**Impact:** disclosure of restricted media URLs or captions to an unauthorized requester, and an unsafe read boundary if an unintended transcript path entered database state.

**Applied repair:** a shared read-only content policy checks the authenticated viewer against the applicable ownership, privacy, purchase/rental, subscription, approval, age, blocking and geographic conditions before protected data is returned. Main and playlist embed responses apply the same viewer policy. VTT paths must match the expected transcript file and remain within the transcript directory. Remote captions are fetched through the guarded path rather than redirecting the client to a raw protected object. Direct transcript-file requests are internally routed through the access-checked handler. This application-response repair does not retroactively revoke previously issued raw media URLs or change external storage-object permissions.

## 23. Embed parameters were emitted into HTML/URL contexts without appropriate validation

**File:** `sources/embed/content.php`.

**Defect:** a requested height was passed through `strip_tags` and then emitted into iframe attributes; that operation did not remove attribute-breaking quotes. A playlist URL also appended a raw color parameter that reached a theme's inline JavaScript `load_next(...)` argument.

**Impact:** reflected attribute injection through height and an unsafe inline-JavaScript argument boundary through playlist color.

**Applied repair:** height is a bounded numeric value; color is a constrained hex value and URL query values are encoded. Tests exercise attribute-breaking input and valid supported values.

## 24. Bundled dependencies contained published security defects

The audit verified affected **installed** versions rather than assuming that every old-looking directory name represented its original contents.

| Bundled component | Observed affected version | Deployed replacement |
| --- | --- | --- |
| Firebase PHP-JWT in Google dependency tree | 6.3.0 | 7.2.1 with a compatible updated Google auth dependency tree |
| Guzzle | 7.4.5 | 7.16.1 |
| Guzzle PSR-7 | 2.4.0 | 2.13.2 |
| AWS SDK | 3.232.2 | 3.403.3 |
| JmesPath | 2.6.1 | 2.9.2 |
| HybridAuth | 3.8.2 | 3.13.0 |
| getID3 | Actual code 1.9.27 despite the directory name `getID3-1.9.14` | 1.9.28 |

**Verification:** six installed Composer audits after deployment reported zero known advisories for the audited projects. Offline smoke checks covered JWT verification, HTTP URI handling, Google/AWS client construction, HybridAuth configuration and both dependency autoload orders. These are scoped results, not a claim that every application dependency or unknown vulnerability was eliminated.

Affected browser code included old jQuery/UI, Bootstrap, Moment, DataTables and CodeMirror. Deployed replacements include jQuery 3.7.1, jQuery UI 1.13.3, Moment 2.31.0, DataTables 1.13.11 and CodeMirror 5.65.21. Admin Bootstrap is 4.6.2. Theme Bootstrap uses verified Ubuntu/Debian 3.4.1 security backports for CVE-2024-6484, CVE-2024-6485 and CVE-2025-1647; the upstream numeric version alone does not describe the deployed patched bytes.

Selected primary advisories for the affected bundled code:

- [Firebase PHP-JWT weak encryption](https://github.com/advisories/GHSA-2x45-7fc3-mxwq).
- [HybridAuth certificate validation](https://github.com/advisories/GHSA-r3hf-q3mf-7h6w).
- [getID3 crafted QuickTime memory exhaustion](https://github.com/JamesHeinrich/getID3/security/advisories/GHSA-4pp3-6hmx-mfmg).
- [Bootstrap security-backport tracking](https://security-tracker.debian.org/tracker/CVE-2025-1647).

The bundled JSZip prototype-pollution defect was addressed with 2.7.0.

## 25. Editor initial values could break out of textarea/attribute contexts

**Files:** `admin-panel/pages/edit-article/content.html`, `admin-panel/pages/edit-terms-pages/content.html`, `sources/edit_articles/content.php`, and corresponding default/youplay editor templates.

**Defect:** stored descriptions and decoded article/terms HTML were written raw into `<textarea>` elements. Titles, tags and image values also entered quoted HTML attributes without the required context escaping. A closing textarea sequence is interpreted by the browser before an editor library can sanitize its content. Dynamic terms keys also entered inline JavaScript/selector contexts unsafely.

**Impact:** stored HTML/script injection while opening affected editing screens.

**Applied repair:** values are escaped for their actual HTML context while preserving the decoded editable content. Dynamic terms targets use JSON hex escaping and `document.getElementById`, avoiding raw interpolation into inline JavaScript.

**Verification:** six Chromium cases using actual PHP controller/template fragments tested closing-textarea, quote, script-end and UTF-8 values. Original textarea values survived round-trip, with no injected image/script/event attributes or execution.

## 26. Vulnerable editor code and incompatible editor APIs were deployed

**Files:** `admin-panel/vendors/tinymce/js/tinymce/`, `themes/default/js/tinymce/js/tinymce/`, `themes/youplay/js/tinymce/js/tinymce/`, and the nine editor initialization locations in each application.

**Defects:** the deployed editor branches were TinyMCE 4.7.1 and 5.7.0, affected by published XSS/mutation-XSS defects. The terms screen also used removed `tinymce.editors[id]` access and was missing from the conditional editor loader.

**Applied repair:** all six production/staging editor directories now contain verified TinyMCE 8.10.2. A shared initializer translates existing supported controls/options, handles merged/obsolete plugin requests and preserves a usable textarea on asset-load failure. Terms use `tinymce.get(id)` and load the editor. Core/subasset cache revisions were updated.

**Verification:** twelve real Chromium fixtures covered all nine screen configurations, existing rich-text preservation, keyboard edits and save, dialogs, sanitization, multiple UTF-8 terms editors, failed-asset fallback and dark/mobile layouts. Publicly served core files and supporting assets matched the deployed version/hashes. These tests did not perform authenticated production content writes.

Primary examples: [TinyMCE mutation XSS](https://github.com/advisories/GHSA-v626-r774-j7f8), [undo/redo/content API XSS](https://github.com/advisories/GHSA-v65r-p3vv-jjfv).

## 27. Tag entry called a jQuery API absent from the deployed runtime

**Files:** `themes/default/js/tag-it.js`, `themes/youplay/js/tag-it.js`.

**Defect:** `.andSelf()` was still called although the already-deployed jQuery 3.2.1 did not provide that method. Tag initialization failed in the baseline compatibility check.

**Applied repair:** the equivalent supported `.addBack()` call is used. Assigned-tag-value and relevant existing plugin compatibility checks passed with the deployed jQuery/UI versions.

## 28. Article bodies were decoded into HTML without server-side sanitization

**Files:** `ajax/articles.php`, `ajax/ap.php`, `ajax/admin.php`, `sources/read/content.php`, the three mobile article readers, shared `assets/includes/richtext_security.php`.

**Defect:** six article writers applied `htmlspecialchars()` to submitted HTML, and readers decoded it back into active HTML without sanitizing it. A direct request bypassed the browser editor entirely. User submissions and edits are inactive until approval: the unsafe body was reachable in authorized owner/administrator previews and could reach public readers after approval.

**Impact:** stored script/event execution when an affected article was rendered. Updating the editor alone did not close this server-side defect.

**Applied repair:** HTMLPurifier 4.19.1 sanitizes every article writer before the existing storage encoding. The public reader sanitizes decoded legacy rows without rewriting the database. Mobile readers sanitize their `orginal_text` field and derive plain text from sanitized content. The policy retains ordinary formatting, tables, links, images and UTF-8 while excluding executable elements, event attributes, unsafe URI schemes, foreign namespaces, forms and frames. It does not fetch destinations. The library is installed separately under `assets/libs/security-html` with a locked dependency; its installed Composer audit reported zero advisories.

**Verification:** complete writer/public-read handlers passed 189 cases and mobile readers passed 81. Chromium parsed 273 sanitized results without execution, unsafe attributes or forbidden elements; original-code controls demonstrated execution. Both real PHP-FPM pools passed with Snuffleupagus enabled and zero warnings. Tests used in-memory records and did not write production articles.

Dependency source: [HTMLPurifier 4.19.1 release](https://github.com/ezyang/htmlpurifier/releases/tag/v4.19.1).

## 29. Article text was reinterpreted as template directives after insertion

**Files:** `assets/includes/functions_one.php::PT_LoadPage()`, the administrator page loader, article read/edit handlers and templates, shared `assets/includes/richtext_security.php`.

**Defect:** page loaders substituted `{{ME ...}}` and `{{CONFIG ...}}` after inserting article text. Article-body tokens could therefore expand configuration/user values as markup, including after initial HTML sanitization. Legacy bodies entering edit forms could also be expanded before an editor saved them.

**Impact:** stored article content crossed into the template instruction boundary; inserted values could defeat the initial HTML safety boundary or be saved as expanded content. Tests demonstrated this with inert synthetic configuration/user markers rather than real secrets.

**Applied repair:** literal braces are encoded after purification, so browser-visible article text remains unchanged while template loaders treat it as literal content. The same protection precedes legacy body insertion into themed and administrator edit forms. Decoded rich HTML is escaped for the textarea including its existing entities, preserving literal code examples, braces and UTF-8 through editor saves.

**Verification:** three actual-template-loader cases and 81 complete legacy edit renders passed. Chromium verified the exact safe textarea content in all 81 cases; nine real TinyMCE edit/save round trips retained literal tokens and code text. Their saved values passed 63 writer/public-read and 27 mobile-read cases without expanding or saving the synthetic markers.

## 30. HTTP scheduler entry points had no caller authorization

**Files:** root `cronjob.php`, `transcribe-cron.php`, `seo-pipeline-cron.php`.

**Defect:** direct HTTP requests initialized and invoked operator jobs without proving that the caller was an authorized scheduler/operator.

**Impact:** a public caller could invoke scheduled work outside the intended schedule. This finding does not establish that every job invocation succeeded or that jobs were historically abused.

**Applied mitigation:** Nginx now restricts those exact endpoints to the approved operator/server sources using the restored client address. Other callers are denied before PHP runs. The three PHP scripts were not changed to implement an application-level authorization scheme.

**Verification:** the final routing configuration passed 62 isolated cases, thirty live health checks and four live untrusted-source denials, including a spoofed trusted-client header. Static probes exercised the access boundary without manually invoking the real jobs.

## Verification boundaries

- Authentication changes: 176 behavioral fixtures and 64 PHP syntax checks passed at the recorded checkpoint.
- Conversion/path changes: 132 behavioral cases passed.
- Shared request/input checks: 63 helper cases and nine actual endpoint/origin guard cases passed.
- Upload checks: 209 content-validation and fifteen write-input binding cases passed.
- SQL/content-access changes: 166 behavioral fixtures and 30 PHP syntax checks passed.
- Backend permissions: 412 fixtures and four final PHP syntax checks passed. A separate read-only privileged-field review passed 36 hostile-field account-update fixtures without source changes.
- Real FPM runtime: 56 helper checks across both pools passed, with no PHP/FastCGI warnings.
- Editor verification: twelve Chromium editor cases and six PHP-context XSS browser cases passed.
- Article bodies/templates: 273 sanitized browser results, 81 legacy edit renders, nine real editor round trips and the actual-handler cases detailed above passed.
- Broad PHP 8.2 syntax pass: 13,599 PHP-like files across production/staging, zero failures. A later 536-file source delta also passed; subsequent article and permission changes received separate final syntax checks.

Fixtures use actual endpoint code with synthetic users, in-memory database/query capture and controlled network/mail stubs where appropriate. They do not establish the historical exploitation of every listed issue, or replace real-provider integration tests. No generic antivirus alert is included here as a code defect without independent code/evidence review.
