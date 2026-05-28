<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Configuration saved</title>
    <style nonce="<?= htmlspecialchars((string)($vars['cspNonce'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      body {
        font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
        margin: 2rem;
      }
    </style>
  </head>
  <body>
    <h1>Done</h1>
    <p>
      The settings are saved in the configured encrypted storage (<code>config.envs</code> for Studio mode or <code>config.enc</code> for legacy mode).
    </p>
    <p>
      The script placed the decrypted values in <code>$SRVENV</code> and <code>$_SESSION['ENV']</code> (in the memory of this request).
    </p>
    <p>
      Now you can include `_init.php` in your project to access the decrypted configuration.
    </p>
  </body>
</html>
