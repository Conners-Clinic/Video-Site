# Local configuration

`config.php` and `nodejs/config.json` contain server-local credentials and are excluded from source control. Preserve them on an existing deployment. For a new Node deployment, copy `nodejs/config.example.json` to `nodejs/config.json` and supply local values.

User uploads, runtime caches, backup files, and incident payloads are excluded. The malicious `web/index.php` was removed at the owner's request and is absent from this branch.

This source snapshot includes the application security and dependency changes. Host firewall and Nginx restrictions are managed separately on the server.
