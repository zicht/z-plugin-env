# Z plugin to work with remote enviroments

This plugin provides a set of utilities and tasks to work with remote SSH
environments

## Usage

To access remotes, you can organize them using the `envs` setting:

```
plugins: ['env']

envs:
    testing: 
        ssh: foo@bar
        root: /var/www/my-site
```

You can now SSH into this remote by running:
```
z env:ssh testing
```

Unless you have already shared your public key with this environment, you'd be
asked for your SSH password. I'd recommend to share your public key with the
remote, so you don't have to supply your password everytime the plugin tries to
access it. Of course, protecting your keyfile with a passphrase would be
sensible.

## Share your key
Using the `z env:ssh-copy-id` can copy your key to the remote. Of course, this
is a convenience command, which simply wraps around catting your public key to
the remote's user's `~/.ssh/authorized_keys` files. It doesn't bother if your
ssh is already "connectable".

## Executing remote commands

You can execute remote commands by accessing the shell:
```
z env:ssh testing
```

Run a command directly within the remote's root dir:

```
z env:ssh testing "rm -rf ./cache"
```

## Accessing remotes from your Z file:

You have a few options to do this. The most intuitive would be to implement
your own ssh command:

```
envs:
    testing:
        ssh: foo@bar
        root: /var/www/my-site

tasks:
    flush-cache:
        args:
            target_env: ?
        do: ssh $(envs[target_env].ssh) "rm -rf $(path(envs[target_env].root, cache))"
```

You can also use something that's called "decorators" to wrap commands in the
remote shell. This is shorter, but it is especially useful since you do not
need to wrap you command in quotes (which can become pretty hairy), and the
`ssh` function respects your current shell.

```
tasks:
    flush-cache:
        args:
            target_env: ?
        do: @(sh ssh(target_env)) rm -rf $(path(envs[target_env].root, cache))
```

_Note_: Under the hood, the contents of the command are fed to the STDIN of the
shell that is the result of the `ssh` function call. You can verify this using
`z z:eval 'ssh("production")'`. This will display the current shell `SHELL`
(which is `/bin/bash -e` by default, and adds the `x` flag when in debugging
mode) executed on the remote within an SSH. This effectively creates a pipe to
which the command's content is streamed. You can mix and match this with other
shells (for example a remote mysql client). Read the Z docs for more
information on how to leverage this.


# Maintainer(s)
* Gerard van Helden <gerard@zicht.nl>

