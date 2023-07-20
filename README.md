CLIup
=====

Simple and efficient self-hosted, CLI-oriented file sharing service over HTTP.


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
* Bulletproof encryption of the files on the server
* A scalable service able to serve thousands of requests


# Nice! Is there a demo? 👀

Well, no. For obvious reasons, running a public anonymous file-sharing service is not a good idea
these days.

So if you want to test it, install it on your own server! 👷‍♀️


# Use 🚀

## Send

```shell
# Easiest & shortest, using curl + PUT
$ curl -T myfile.bin http://myhost.tld
File uploaded successfully. The password for your file is:
institute-crisis-individual

# [Alternative] If you prefer wget or you don't have curl
# You need to set the name of your file in the path after the host
$ wget --method PUT --body-file=myfile.bin http://myhost.tld/myfile.bin -O - -nv
```

> [Explain me the `wget` example](https://explainshell.com/explain?cmd=wget+--method+PUT+--body-file%3D5MB.file+http%3A%2F%2Flocalhost%3A8080%2Fmyfile.bin+-O+-+-nv)

You may also use POST like so:

```shell
# You need to set the name of your file in the path after the host
$ curl -F "data=@myfile.bin" http://myhost.tld/myfile.bin
File uploaded successfully. The password for your file is:
foundation-balance-february
```

☝ _There is no `wget` alternative with POST as of today._

## Retrieve

```shell
# Use the password you got previously
$ curl http://myhost.tld/institute-crisis-individual > myfile.bin

# Or with wget
$ wget http://myhost.tld/institute-crisis-individual -O myfile.bin -q
```

## Delete

To delete a file, you only need its password too:

```shell
$ curl -X DELETE http://myhost.tld/foundation-balance-february
OK, the file has been deleted.
```


# Serve 🌎

## Locally

```shell
# By default, will listen on localhost:8080
$ make server-start
```

## Docker 🐳 

Quick & dirty:

```shell
$ docker run -d -p 80:8080 nanawel/cliup
```

With proper upload folder and UID/GID:

```shell
$ mkdir -m 700 ./uploads
$ chown 1000:1000 ./uploads
$ docker run -d -p 80:8080 -v ./uploads:/uploads -u 1000:1000 nanawel/cliup
```

You might want to use the provided [`docker-compose.yml`](./docker/docker-compose.yml) instead as a base.

## Reverse-proxy configuration

Of course you should use SSL/TLS with this service. But it's not embedded in the Docker itself
nor in the server code. So you should configure properly a reverse-proxy with the solution
of your choice.

### Nginx

You must set an appropriate `client_max_body_size` value depending on the `MAX_UPLOAD_SIZE` you
set for the service (see §Configuration below).

Example in your vhost (here for 100 MB max):

```
client_max_body_size 100M;
```

### Apache

With Apache, you have 2 configurations value to check: `LimitRequestBody` **but also** `Proxy100Continue`
that must be **disabled** (Off).

So for a hard limit to 100 MB, this should be:

```apacheconf
LimitRequestBody 104857600
Proxy100Continue Off
```

> See more: https://httpd.apache.org/docs/2.4/fr/mod/mod_proxy.html#proxy100continue

## Purge ♻

Files are not automatically removed as there is no cronjob inside the Docker container.
You should add such a job yourself on the host if you want expired files to be _really_
deleted and thus space reclaimed:

```shell
$ docker exec <your-container> php admin.php purge
```

## Configuration

You can use the following environment variables to configure the service:

```
    BASE_URL              (default: <empty>)
    DEBUG                 (default: 0)
    ENCRYPTION_ENABLED    (default: 0)
    EXPIRATION_TIME       (default: 86400      => 1 day)
    HASH_SALT             (default: <empty>)
    PASS_WORDS_COUNT      (default: 3)
    LOG_ACTIVITY          (default: 1)
    MAX_UPLOAD_SIZE       (default: 1048576    => 1 MB)
    TMP_DIR               (default: "/tmp")
    TRACE_CLIENT_INFO     (default: 1)
    UPLOAD_DIR            (default: "/tmp" or "/uploads" on Docker)
    UPLOAD_DIR_PERMS      (default: "0700")
    UPLOAD_NAME_MAX_LEN   (default: 255)
    WORDSLIST_FILE        (default: "./wordslist.txt")
```
