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
      .tip {
        color:#555;
        font-size:.9rem;
        margin-bottom: 1rem;
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
      .msg-success {
        background:#e9fbe9;
        border:1px solid #9bd79b;
        color:#106010;
      }
      form {
        margin-top: .5rem;
      }
      .rows {
        margin-bottom: 1rem;
      }
      .row {
        display:flex;
        gap:.5rem;
        margin-bottom:.5rem;
      }
      .row input {
        flex:1 1 0;
        padding:.35rem .5rem;
        border:1px solid #ccc;
        border-radius:4px;
        font-size:.9rem;
      }
      .row input.key {
        max-width: 40%;
      }
      .row button.remove {
        border:none;
        background:#eee;
        padding:0 .6rem;
        border-radius:4px;
        cursor:pointer;
        font-size:1.1rem;
        line-height:1.25;
      }
      .row button.remove:hover {
        background:#ddd;
      }
      .controls {
        display:flex;
        gap:.5rem;
        margin-top:.5rem;
        flex-wrap: wrap;
      }
      button[type=submit], .btn-secondary {
        border:none;
        border-radius:4px;
        padding:.45rem .9rem;
        cursor:pointer;
        font-size:.9rem;
      }
      button[type=submit] {
        background:#0070f3;
        color:#fff;
      }
      button[type=submit]:hover {
        background:#005ad1;
      }
      .btn-secondary {
        background:#eee;
      }
      .btn-secondary:hover {
        background:#ddd;
      }
      code {
        background:#f0f0f0;
        padding:0 .2rem;
        border-radius:3px;
        font-size:.85em;
      }
    </style>
  </head>
  <body>
    <div class="wrap">
      <h1>Encrypted variables</h1>
      <p class="tip">
        Here you can edit <code>KEY = value</code> pairs, which will be saved in <code>config.enc</code> (encrypted) or <code>config.json</code> (open JSON).
        The values are hidden (type <code>password</code>), but can be changed.
      </p>
      <?= ($vars['error'] ?? '') ?><?= ($vars['success'] ?? '') ?>
      <form method="post" autocomplete="off">
        <div id="rows" class="rows">
        <?php if (isset($vars['prefill'])) foreach ($vars['prefill'] as $row) { ?>
          <div class="row">
            <input class="key"   name="cfg_key[]"   type="text"      placeholder="KEY"   value="<?= htmlspecialchars((string)($row['key']   ?? ''), ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" spellcheck="false">
            <input class="value" name="cfg_value[]" type="password"  placeholder="value" value="<?= htmlspecialchars((string)($row['value'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" spellcheck="false">
            <button type="button" class="remove" onclick="removeRow(this)" aria-label="Remove row">&times;</button>
          </div>
        <?php } ?>
        </div>
        
        <div class="controls">
          <input type="hidden" name="save_mode" id="save_mode" value="enc">
          
          <button type="button" class="btn-secondary" onclick="addRow()">+ Add a row</button>
          <button type="button" class="btn-secondary" onclick="toggleVisibility()">Show/hide values</button>
          
          <!-- Selecting a JSON file to import -->
          <input type="file" id="json_file" accept="application/json" class="btn-secondary">
          <button type="button" class="btn-secondary" onclick="loadJsonIntoForm()">
            Load JSON into form
          </button>
          
          <!-- save to encrypted config.enc -->
          <button type="submit" onclick="document.getElementById('save_mode').value='enc'">
            Save (encrypted)
          </button>
          
          <!-- Download config.json to your browser -->
          <button type="submit" onclick="document.getElementById('save_mode').value='json_download'">
            Download JSON
          </button>
        </div>
      </form>
      
      <p class="tip" style="margin-top:1rem;">
        Once saved, the configuration file can be read in code by decrypting <code>config.enc</code> and using
        the values from <code>\$_SESSION['ENV']</code> or from your wrapper function. The file
        <code>config.json</code> stores the same data in cleartext—use it only in a
        secure environment.
      </p>
    </div>
    
    <template id="row-template">
      <div class="row">
        <input class="key"   name="cfg_key[]"   type="text"     placeholder="KEY"   autocomplete="off" spellcheck="false">
        <input class="value" name="cfg_value[]" type="password" placeholder="value" autocomplete="off" spellcheck="false">
        <button type="button" class="remove" onclick="removeRow(this)" aria-label="Remove row">&times;</button>
      </div>
    </template>
    
    <script>
      function addRow() {
        const tpl  = document.getElementById('row-template');
        const rows = document.getElementById('rows');
        if (!tpl || !rows) return;
    
        const clone = tpl.content.firstElementChild.cloneNode(true);
        rows.appendChild(clone);
      }
    
      function removeRow(btn) {
        const row = btn.closest('.row');
        if (row) {
          row.remove();
        }
      }
    
      function toggleVisibility() {
        const inputs = document.querySelectorAll('input.value');
        inputs.forEach((inp) => {
          inp.type = (inp.type === 'password') ? 'text' : 'password';
        });
      }
    
      // ==== New: Loading parameters from a JSON file ====
    
      function loadJsonIntoForm() {
        const input = document.getElementById('json_file');
        if (!input || !input.files || !input.files[0]) {
          alert('Please select a JSON file.');
          return;
        }
    
        const file = input.files[0];
        const reader = new FileReader();
    
        reader.onload = function(e) {
          try {
            const text = e.target.result;
            const obj  = JSON.parse(text);
    
            if (typeof obj !== 'object' || obj === null) {
              alert('Invalid JSON format (expected object with key-value pairs).');
              return;
            }
    
            const rowsContainer = document.getElementById('rows');
            if (!rowsContainer) return;
    
            // clear existing lines
            rowsContainer.innerHTML = '';
    
            // we create rows for each key
            Object.keys(obj).forEach((key) => {
              // We do not insert the service field into the form
              if (key === 'saved_at') return;
              
              const value = obj[key];
              
              const row = document.createElement('div');
              row.className = 'row';
              
              const inputKey = document.createElement('input');
              inputKey.className = 'key';
              inputKey.name = 'cfg_key[]';
              inputKey.type = 'text';
              inputKey.placeholder = 'KEY';
              inputKey.autocomplete = 'off';
              inputKey.spellcheck = false;
              inputKey.value = key;
              
              const inputVal = document.createElement('input');
              inputVal.className = 'value';
              inputVal.name = 'cfg_value[]';
              inputVal.type = 'password';
              inputVal.placeholder = 'value';
              inputVal.autocomplete = 'off';
              inputVal.spellcheck = false;
              inputVal.value = value !== null && value !== undefined ? String(value) : '';
              
              const btnRemove = document.createElement('button');
              btnRemove.type = 'button';
              btnRemove.className = 'remove';
              btnRemove.innerHTML = '&times;';
              btnRemove.setAttribute('aria-label', 'Remove row');
              btnRemove.onclick = function() {
                row.remove();
              };
              
              row.appendChild(inputKey);
              row.appendChild(inputVal);
              row.appendChild(btnRemove);
              
              rowsContainer.appendChild(row);
            });
            
            if (!rowsContainer.children.length) {
              alert('No key-value pairs were found in the JSON (except saved_at).');
            }
          } catch (err) {
            console.error(err);
            alert('Error reading JSON: ' + err);
          }
        };
        
        reader.onerror = function() {
          alert('Error reading file.');
        };
        
        reader.readAsText(file, 'utf-8');
      }
    </script>
  </body>
</html>
