const fs = require('fs');
const path = require('path');

// Paths
const packageJsonPath = path.join(__dirname, '../package.json');
const phpFilePath = path.join(__dirname, '../gp-translator-signup.php');

// Read package.json
if (!fs.existsSync(packageJsonPath)) {
  console.error('Error: package.json not found.');
  process.exit(1);
}

const packageJson = JSON.parse(fs.readFileSync(packageJsonPath, 'utf8'));
const version = packageJson.version;

if (!version) {
  console.error('Error: No version field found in package.json.');
  process.exit(1);
}

// Read gp-translator-signup.php
if (!fs.existsSync(phpFilePath)) {
  console.error('Error: gp-translator-signup.php not found.');
  process.exit(1);
}

let phpContent = fs.readFileSync(phpFilePath, 'utf8');

// Replace Plugin Header Version:
// Match pattern like: * Version:      1.0.1
const versionHeaderRegex = /(\*\s*Version:\s*)(\S+)/;
if (versionHeaderRegex.test(phpContent)) {
  phpContent = phpContent.replace(versionHeaderRegex, `$1${version}`);
  console.log(`Updated plugin header version to: ${version}`);
} else {
  console.warn('Warning: Plugin Version header not found or matched.');
}

// Write the updated file back
fs.writeFileSync(phpFilePath, phpContent, 'utf8');
console.log('Version update complete.');
