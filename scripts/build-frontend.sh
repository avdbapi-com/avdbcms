#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NODE_VERSION="${NODE_VERSION:-22.12.0}"
TOOLS_DIR="${ROOT_DIR}/.tools"
NODE_ROOT="${TOOLS_DIR}/node"
DOWNLOAD_DIR="${TOOLS_DIR}/downloads"

node_ok() {
    "$1" -e '
const parts = process.versions.node.split(".").map(Number);
const [major, minor] = parts;
const ok = (major === 20 && minor >= 19) || (major === 22 && minor >= 12) || major > 22;
process.exit(ok ? 0 : 1);
' >/dev/null 2>&1
}

find_system_node() {
    if command -v node >/dev/null 2>&1 && node_ok "$(command -v node)"; then
        command -v node
        return 0
    fi

    return 1
}

platform_arch() {
    local os arch
    os="$(uname -s | tr '[:upper:]' '[:lower:]')"
    arch="$(uname -m | tr '[:upper:]' '[:lower:]')"

    case "${os}" in
        linux) os="linux" ;;
        darwin) os="darwin" ;;
        *) echo "Unsupported OS for portable Node: ${os}" >&2; return 1 ;;
    esac

    case "${arch}" in
        x86_64|amd64) arch="x64" ;;
        aarch64|arm64) arch="arm64" ;;
        *) echo "Unsupported CPU for portable Node: ${arch}" >&2; return 1 ;;
    esac

    printf '%s-%s' "${os}" "${arch}"
}

install_portable_node() {
    local target archive url dist node_bin
    target="$(platform_arch)"
    dist="node-v${NODE_VERSION}-${target}"
    node_bin="${NODE_ROOT}/${dist}/bin/node"

    if [ -x "${node_bin}" ] && node_ok "${node_bin}"; then
        echo "${node_bin}"
        return 0
    fi

    mkdir -p "${NODE_ROOT}" "${DOWNLOAD_DIR}"
    archive="${DOWNLOAD_DIR}/${dist}.tar.xz"
    url="https://nodejs.org/dist/v${NODE_VERSION}/${dist}.tar.xz"

    echo "Downloading Node.js ${NODE_VERSION} for ${target}..." >&2
    if command -v curl >/dev/null 2>&1; then
        curl -fL "${url}" -o "${archive}"
    elif command -v wget >/dev/null 2>&1; then
        wget -O "${archive}" "${url}"
    else
        echo "curl or wget is required to download portable Node.js." >&2
        return 1
    fi

    rm -rf "${NODE_ROOT:?}/${dist}"
    tar -xJf "${archive}" -C "${NODE_ROOT}"
    rm -f "${archive}"

    if ! node_ok "${node_bin}"; then
        echo "Downloaded Node.js is not compatible with Vite." >&2
        return 1
    fi

    echo "${node_bin}"
}

NODE_BIN="$(find_system_node || install_portable_node)"
NPM_BIN="$(dirname "${NODE_BIN}")/npm"

if [ ! -x "${NPM_BIN}" ]; then
    echo "npm was not found next to ${NODE_BIN}." >&2
    exit 1
fi

cd "${ROOT_DIR}"
export PATH="$(dirname "${NODE_BIN}"):${PATH}"

if [ ! -d node_modules ] || [ ! -x node_modules/.bin/vite ]; then
    if [ -f package-lock.json ]; then
        "${NPM_BIN}" ci
    else
        "${NPM_BIN}" install
    fi
fi

"${NPM_BIN}" run build:vite
