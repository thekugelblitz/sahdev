const fs = require('fs');
const content = fs.readFileSync('controllers/AdminController.php', 'utf8');
const lines = content.split('\n');

const navLines = [];
lines.forEach((line, idx) => {
  if (line.includes('action=settings') || line.includes('action=providers') || line.includes('action=prompt_manager') || line.includes('action=mobile_app')) {
    navLines.push(`${idx+1}: ${line.trim()}`);
  }
});

console.log(`Found ${navLines.length} occurrences. First 20:`);
navLines.slice(0, 20).forEach(l => console.log(l));
