# Local node of the Hostrema test environment (infra/local).
#
# A "server" with its own Docker engine (Docker-in-Docker) that the control plane reaches by SSH,
# like the nodes of a region in production. The applications of the customers, the Traefik proxy
# and the build helper all run inside this container, so nothing touches the Docker of your machine.
ARG DOCKER_VERSION=27
FROM docker:${DOCKER_VERSION}-dind

# Tools Coolify uses on its servers (bash for every remote command), the SSH server and, when the
# image lacks them, the compose and buildx plugins (after upgrading the packages of the image, whose
# SSH client would conflict with a newer server package). Root logs in only with the key of the control
# plane: its password becomes "*" (no password, not locked), because sshd refuses any key of a
# locked account.
RUN apk upgrade --no-cache \
    && apk add --no-cache bash coreutils findutils iproute2 curl jq git openssh-server tar gzip \
    && (docker compose version >/dev/null 2>&1 || apk add --no-cache docker-cli-compose) \
    && (docker buildx version >/dev/null 2>&1 || apk add --no-cache docker-cli-buildx) \
    && sed -i -e 's/^root:!*:/root:*:/' /etc/shadow \
    && ssh-keygen -A \
    && mkdir -p /root/.ssh /data/coolify \
    && chmod 700 /root/.ssh

COPY --chmod=755 entrypoint.sh /usr/local/bin/hostrema-node

EXPOSE 22 80 443
HEALTHCHECK --interval=5s --timeout=8s --start-period=10s --retries=60 \
    CMD docker info >/dev/null 2>&1 && bash -c 'exec 3<>/dev/tcp/127.0.0.1/22 && IFS= read -r -t 3 banner <&3 && [[ $banner == SSH-* ]]' || exit 1
ENTRYPOINT ["/usr/local/bin/hostrema-node"]
