var companyName = 'Promise Technology, Inc.';
var brandName = 'PROMISE';
var modelName = 'Storage Appliance';
var model2Name = 'E-Class';
var model3Name = 'M-Class';
var model4Name = 'Storage Appliance';
var model5Name = 'Storage Appliance';
var softwareName = 'WebPAM PROe';
var companyLink = '<a target=_blank href=http://www.promise.com/>www.promise.com</a>';

function writeCompanyName() { document.write(companyName); }
function writeBrandName() { document.write(brandName); }
function writeModelName() { document.write(modelName); }
function writeModel2Name() { document.write(model2Name); }
function writeModel3Name() { document.write(model3Name); }
function writeModel4Name() { document.write(model4Name); }
function writeModel5Name() { document.write(model5Name); }
function writeSoftwareName() { document.write(softwareName); }
function writeCompanyLink() { document.write(companyLink); }

function writeGoBack() {
	if(!/help\.php\?file=/.test(document.location.href))document.write('<table><tr><td><img src=helpImages/ball-back.gif></td><td><a href=javascript:history.back()>Go Back</a></td></tr></table>');
}
