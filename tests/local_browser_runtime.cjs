// Existing installed Chrome, disposable profiles, loopback fixture pages only.
const fs=require('node:fs'),path=require('node:path');
exports.launch=async function(puppeteer){
 const root=path.resolve(__dirname,'..');
 const parent=fs.existsSync(path.join(root,'phase6-development'))?path.join(root,'phase6-development/tools/chrome-temp'):path.join(root,'tools/chrome-temp');
 fs.mkdirSync(parent,{recursive:true});
 const temporary=fs.mkdtempSync(path.join(parent,'verified-browser-'));
 return puppeteer.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',userDataDir:path.join(temporary,'profile'),env:{...process.env,TEMP:temporary,TMP:temporary},headless:true,
  // The surrounding Windows execution sandbox prevents the nested Chrome sandbox
  // from starting. These test processes open only marked local fixtures/mocks;
  // each caller intercepts page requests. Never use this launcher for public browsing.
  args:['--no-sandbox','--no-proxy-server','--disable-background-networking','--disable-component-update','--disable-sync','--disable-extensions','--no-first-run','--metrics-recording-only','--disable-domain-reliability','--no-pings','--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1, EXCLUDE localhost, EXCLUDE [::1]','--disable-features=MediaRouter,OptimizationHints,AutofillServerCommunication']});
};
