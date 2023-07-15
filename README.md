CLIup
=====

Simple and efficient CLI-oriented file sharing service.


![Demo](doc/demo.gif)

# Why CLIup? 🤔

I needed a file sharing service that could be easily used with only `curl` and `wget` for maximum
compatibility with all possible contexts (even light live environments or BusyBox-like shells).

What I also needed, was a way to get access to a file once uploaded using a **moderately secure** key
that I could remember for at least some minutes, enough to retrieve the file from another computer
with... you guessed it, another `curl` or `wget`.

👍 What you get with CLIup:

* Self-hosted service up and running in seconds
* Upload files with PUT or POST requests from your favorite terminal
* Get a random password composed of several common words in return

👎 What you **don't** get with CLIup:

* An end-to-end encrypted file sharing service
* Encrypted files on server
* A scalable service able to serve thousands of requests

# Use 🚀

```shell
# Easiest, using curl + PUT
curl -T myfile.bin myhost.tld
File uploaded successfully. The password for your file is:
institute-crisis-individual

# On some other computer
curl myhost.tld/institute-crisis-individual > myfile.bin
```

You may also use POST like so:

```shell
# You need to set the name of your file in the path after the host
curl -F "data=@myfile.bin" myhost.tld/myfile.bin
File uploaded successfully. The password for your file is:
foundation-balance-february
```

To delete a file, you only need its password too:
```shell
curl -X DELETE myhost.tld/foundation-balance-february
OK, the file has been deleted.
```

# Serve 🌎

## Locally

```shell
# >By default, will listen on localhost:8080
make server-start
```

## Docker 🐳 

Quick & dirty:

```shell
docker run -d -p 80:8080 nanawel/cliup
```

With proper upload folder and UID/GID:

```shell
mkdir ./uploads
docker run -d -p 80:8080 -v ./uploads:/srv/uploads -u 1000:1000 nanawel/cliup
```

## Configuration

You can use the following environment variables to configure the service:

```
    BASE_URL            (default: <empty>)
    DEBUG               (default: 0)
    EXPIRATION_TIME     (default: 86400)
    HASH_SALT           (default: <empty>)
    PASS_WORDS_COUNT    (default: 3)
    LOG_ACTIVITY        (default: 1)
    MAX_UPLOAD_SIZE     (default: 1048576 => 1 MB),
    TMP_DIR             (default: "/tmp")
    TRACE_CLIENT_INFO   (default: 1)
    UPLOAD_DIR          (default: "/tmp" or "/srv/uploads" on Docker)
    UPLOAD_DIR_PERMS    (default: "0700")
    UPLOAD_NAME_MAX_LEN (default: 255)
    WORDSLIST_FILE      (default: "./wordslist.txt")
```
