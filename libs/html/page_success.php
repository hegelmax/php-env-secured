<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Configuration saved</title>
  </head>
  <body style="font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin:2rem">
    <h1>Done</h1>
    <p>
      The settings are saved (in the encrypted file <code>config.enc</code> and/or in <code>config.json</code> — depending on the selected mode).
    </p>
    <p>
      The script placed the decrypted values in <code>$SRVENV</code> and <code>$_SESSION['ENV']</code> (in the memory of this request).
    </p>
    <p>
      Now you can include `_init.php` in your project to access the decrypted configuration.
    </p>
  </body>
</html>
