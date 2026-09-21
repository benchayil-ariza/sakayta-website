// services/soapClient.js
// This is where Node.js (JavaScript) CONSUMES the SOAP service written in PHP.
// This one file is your clearest evidence of "heterogeneous systems" - two
// different languages, working together.

const soap = require("soap");

const WSDL_URL = process.env.SOAP_URL || "http://localhost:8080/driverVerification.php?wsdl";
const SOAP_TIMEOUT = 10000; // 10 seconds

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
    let finished = false;
    const timer = setTimeout(() => {
      if (!finished) {
        finished = true;
        reject(
          new Error(
            `SOAP request timed out after ${SOAP_TIMEOUT}ms (is PHP service running at ${WSDL_URL}?)`
          )
        );
      }
    }, SOAP_TIMEOUT);

    soap.createClient(WSDL_URL, { timeout: SOAP_TIMEOUT }, (err, client) => {
      if (finished) return;
      if (err) {
        clearTimeout(timer);
        finished = true;
        return reject(new Error(`Failed to connect to SOAP service: ${err.message}`));
      }
      // NOTE: for a SOAP method call (unlike createClient), the "soap" package
      // expects (args, callback, options) - callback 2nd, options 3rd.
      client.VerifyDriver({ driverId }, (err2, rawResult, rawResponse) => {
        if (finished) return;
        clearTimeout(timer);
        finished = true;
        if (err2) return reject(new Error(`SOAP VerifyDriver error: ${err2.message || JSON.stringify(err2)}`));
        const result = {
          verified: unwrapValue(rawResult?.verified) === true || unwrapValue(rawResult?.verified) === "true",
          licenseNumber: unwrapValue(rawResult?.licenseNumber) || "",
        };
        resolve({ result, rawResponse: rawResponse ? rawResponse.toString() : null });
      }, { timeout: SOAP_TIMEOUT });
    });
  });
}

module.exports = { verifyDriver };