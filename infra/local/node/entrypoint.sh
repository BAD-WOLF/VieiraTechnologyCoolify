#!/bin/sh
# Starts the SSH server and the Docker engine of the local node (infra/local).
# The public key of the control plane is written to /root/.ssh/authorized_keys by
# hostrema-local.sh (kept in the node-ssh volume).
set -eu

mkdir -p /root/.ssh /data/coolify
chmod 700 /root/.ssh
touch /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys

/usr/sbin/sshd -e \
    -o PermitRootLogin=prohibit-password \
    -o PasswordAuthentication=no \
    -o KbdInteractiveAuthentication=no \
    -o AuthorizedKeysFile=.ssh/authorized_keys \
    -o MaxSessions=64 \
    -o MaxStartups=64:30:128 \
    -o ClientAliveInterval=30

# Only the unix socket: a TCP socket would let the applications on the node control its Docker.
exec dockerd-entrypoint.sh dockerd --host=unix:///var/run/docker.sock
