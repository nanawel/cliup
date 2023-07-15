Output demo.gif

Set Padding 20
Set FontSize 16
Set Width 800
Set Height 480
Set TypingSpeed 0.1

Type "curl cliup.example.org"
Sleep 200ms
Enter

Type "dd if=/dev/random of=myfile.bin bs=1M count=1"
Sleep 200ms
Enter 2

Type "curl -sT myfile.bin cliup.example.org | tee >(tail -1 > password)"
Sleep 1000ms
Enter 2

Type "curl -s cliup.example.org/$(cat password) > myfileagain.bin"
Sleep 1000ms
Enter 2

Type "sha1sum myfile*"
Sleep 500ms
Enter

Sleep 12s

Hide
Type "rm -f myfile*.bin password"
Enter
