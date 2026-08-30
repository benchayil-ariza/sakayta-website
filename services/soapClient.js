// services/soapClient.js
// This is where Node.js (JavaScript) CONSUMES the SOAP service written in PHP.
// This one file is your clearest evidence of "heterogeneous systems" - two
// different languages, working together.

const soap = require("soap");

const WSDL_URL = process.env.SOAP_URL || "http://localhost:8080/driverVerification.php?wsdl";

// The WSDL uses RPC/encoded style, so the soap library sometimes wraps
// primitive values like { attributes: {...}, "$value": true } instead of
// returning a plain boolean/string. This unwraps them so the rest of the
// app can just check result.verified === true like normal.
function unwrapValue(field) {
  if (field && typeof field === "object" && "$value" in field) {
    return field.$value;
  }
  return field;
}

function verifyDriver(driverId) {
  return new Promise((resolve, reject) => {
    soap.createClient(WSDL_URL, (err, client) => {
      if (err) return reject(err);
      client.VerifyDriver({ driverId }, (err2, rawResult, rawResponse) => {
        if (err2) return reject(err2);
        const result = {
          verified: unwrapValue(rawResult.verified) === true || unwrapValue(rawResult.verified) === "true",
          licenseNumber: unwrapValue(rawResult.licenseNumber),
        };
        resolve({ result, rawResponse: rawResponse ? rawResponse.toString() : null });
      });
    });
  });
}

module.exports = { verifyDriver };
