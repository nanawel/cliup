HEAD http://cliup-test.local:8080/

HTTP 204
[Asserts]
header "CLIup-Version" exists
header "CLIup-Expiration-Time" matches /\d+/
header "CLIup-Max-Upload-Size" matches /\d+/
header "CLIup-Pass-Words-Count" matches /\d+/
