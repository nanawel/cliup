PUT http://cliup-test.local:8080/__testfile__
file,__testfile__;

HTTP 200
[Captures]
password: header "CLIup-File-Password"

GET http://cliup-test.local:8080/{{password}}

HTTP 200
file,__testfile__;

DELETE http://cliup-test.local:8080/{{password}}

HTTP 200
[Asserts]
body contains "OK"

GET http://cliup-test.local:8080/{{password}}

HTTP 404
