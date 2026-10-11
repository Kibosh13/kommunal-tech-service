import fs from 'node:fs';
import vm from 'node:vm';
const sandbox = {window: {}};
vm.runInNewContext(fs.readFileSync(new URL('../products.js', import.meta.url), 'utf8'), sandbox, {timeout:1000});
fs.writeFileSync(process.argv[2], JSON.stringify(sandbox.window.partsCatalog, null, 2));
