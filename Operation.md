Operation manual
=================

# Running

In order to start the idOS Manager daemon you should run in the terminal:

```
./manager-cli.php daemon:process [-d] [-l path/to/log/file] functionName serverList
```

* `functionName`: gearman function name
* `serverList`: a list of the gearman servers
* `-d`: enable debug mode
* `-l`: the path for the log file

Example:

```
./manager-cli.php daemon:process -d -l log/manager.log manager localhost
```
