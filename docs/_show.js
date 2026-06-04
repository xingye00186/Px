const fs = require('fs');
const c = fs.readFileSync('D:/Px/docs/Px 框架 Vue 3 与 CSS 模板语义对齐 — 大型重构方案.md', 'utf8');
const lines = c.split('\n');
// Show first 80 lines raw
for (let i = 0; i < Math.min(80, lines.length); i++) {
  const l = lines[i];
  console.log((i+1).toString().padStart(3) + '|' + l);
}
