#ddev-generated
# Remove the line above if you don't want this file to be overwritten when you run
# ddev add-on get julienloizelet/ddev-playwright
#
# This file comes from https://github.com/julienloizelet/ddev-playwright
#
ARG PLAYWRIGHT_DOCKER_IMAGE=ghcr.io/xima-media/playwright-ddev:1.59.1
FROM ${PLAYWRIGHT_DOCKER_IMAGE}

ARG PLAYWRIGHT_DOCKER_IMAGE
LABEL PARENT_IMAGE="${PLAYWRIGHT_DOCKER_IMAGE}"

# Remove any existing pwuser/ubuntu users to avoid UID conflicts
RUN deluser --remove-home pwuser || true
RUN deluser --remove-home ubuntu || true
# Accept build arguments for user creation
ARG username
ARG uid
ARG gid
# Ensure tty group exists
RUN getent group tty || groupadd tty
# Create group and user, trying multiple methods for compatibility
RUN (groupadd --gid "$gid" "$username" || groupadd "$username" || true) && \
    (useradd -G tty -l -m -s "/bin/bash" --gid "$username" --comment '' --uid "$uid" "$username" || \
    useradd -G tty -l -m -s "/bin/bash" --gid "$username" --comment '' "$username" || \
    useradd -G tty -l -m -s "/bin/bash" --gid "$gid" --comment '' "$username" || \
    useradd -G tty -l -m -s "/bin/bash" --comment '' "$username")

# Define CAROOT for mkcert
ENV CAROOT=/mnt/ddev-global-cache/mkcert

# Allow the `$username` user passwordless sudo for `mkcert -install`
RUN mkdir -p /etc/sudoers.d && \
    echo "$username ALL=(ALL) NOPASSWD: ALL" > "/etc/sudoers.d/$username" && \
    chmod 0440 "/etc/sudoers.d/$username"

# Prepare KasmVNC
RUN mkdir -p "/home/$username/.vnc"
COPY kasmvnc.yaml xstartup /home/"$username"/.vnc/
RUN touch "/home/$username/.vnc/.de-was-selected"
# We actually disable auth, but KASM complains without it
RUN sudo -u "$username" /bin/bash -c "echo -e \"secret\nsecret\n\" | kasmvncpasswd -wo -u \"$username\""
RUN chown -R "$uid:$gid" "/home/$username"
RUN usermod -a -G ssl-cert "$username"

COPY entrypoint.sh /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
