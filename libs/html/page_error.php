<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <title>Encrypted config editor</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
      body {
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
        margin: 2rem;
        background: #f5f5f7;
      }
      .wrap {
        max-width: 800px;
        margin: 0 auto;
        background: #fff;
        padding: 1.5rem 2rem;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,.05);
      }
      h1 {
        font-size: 1.4rem;
        margin: 0 0 .25rem;
      }
      .msg {
        padding: .6rem .8rem;
        border-radius: 4px;
        margin-bottom: .8rem;
        font-size: .9rem;
      }
      .msg-error {
        background:#ffecec;
        border:1px solid #f5a4a4;
        color:#8b0000;
      }
    </style>
  </head>
  <body>
    <div class="wrap">
      <h1>Script error</h1>
      <?= ($vars['error'] ?? '') ?>
    </div>
  </body>
</html>
