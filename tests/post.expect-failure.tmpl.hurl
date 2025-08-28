PUT http://cliup-test.local:8080/__testfile__
[Multipart]
data: file,__testfile__;

HTTP 413
[Asserts]
header "CLIup-File-Password" not exists
