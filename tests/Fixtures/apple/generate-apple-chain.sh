#!/usr/bin/env bash
# Regenerates the Apple-shaped fixture chain the trust tests pin (tests/Fixtures/apple).
#
# Every certificate carries the marker extension Apple puts on the real one — the leaf
# 1.2.840.113635.100.6.11.1, the intermediate 1.2.840.113635.100.6.2.1 (both DER NULL) —
# so JwsVerifier's policy-OID check sees the shape it guards. The `no-oid-*` certificates
# lack the marker on purpose. The leaf certifies the committed leaf-key.pem, so signing
# tests keep their key. Re-running changes every fingerprint: update the frozen vectors
# in tests/Unit/Providers/Apple/AppleTrustFreezeTest.php afterwards.
set -euo pipefail

cd "$(dirname "$0")"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

cat > "$work/openssl.cnf" <<'CNF'
[ req ]
distinguished_name = dn
prompt = no
[ dn ]
[ v3_root ]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
[ v3_intermediate ]
basicConstraints = critical,CA:TRUE,pathlen:0
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
1.2.840.113635.100.6.2.1 = DER:0500
[ v3_intermediate_no_oid ]
basicConstraints = critical,CA:TRUE,pathlen:0
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
[ v3_leaf ]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature
authorityKeyIdentifier = keyid
1.2.840.113635.100.6.11.1 = DER:0500
[ v3_leaf_no_oid ]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature
authorityKeyIdentifier = keyid
CNF

subject() { echo "/CN=$1/C=AU/ST=Some-State/O=Internet Widgits Pty Ltd"; }
days=3650

ec() { openssl ecparam -name prime256v1 -genkey -noout -out "$1"; }

# issue <subject-cn> <key> <extensions> <out> [<issuer-cert> <issuer-key>]
issue() {
    local cn="$1" key="$2" ext="$3" out="$4"
    if [ $# -eq 4 ]; then
        openssl req -new -x509 -config "$work/openssl.cnf" -extensions "$ext" -key "$key" \
            -subj "$(subject "$cn")" -days "$days" -sha256 -out "$out"
    else
        openssl req -new -config "$work/openssl.cnf" -key "$key" -subj "$(subject "$cn")" -out "$work/req.csr"
        openssl x509 -req -in "$work/req.csr" -CA "$5" -CAkey "$6" -CAcreateserial \
            -extfile "$work/openssl.cnf" -extensions "$ext" -days "$days" -sha256 -out "$out"
    fi
}

ec "$work/root.key"
ec "$work/intermediate.key"
ec "$work/no-oid-intermediate.key"

issue 'Purchases Freeze Root' "$work/root.key" v3_root root.pem
issue 'Purchases Freeze Intermediate' "$work/intermediate.key" v3_intermediate intermediate.pem root.pem "$work/root.key"
issue 'Purchases Freeze Leaf' leaf-key.pem v3_leaf leaf.pem intermediate.pem "$work/intermediate.key"
issue 'Purchases Freeze Leaf' leaf-key.pem v3_leaf_no_oid no-oid-leaf.pem intermediate.pem "$work/intermediate.key"
issue 'Purchases No-OID Intermediate' "$work/no-oid-intermediate.key" v3_intermediate_no_oid no-oid-intermediate.pem root.pem "$work/root.key"
issue 'Purchases Freeze Leaf' leaf-key.pem v3_leaf no-oid-intermediate-leaf.pem no-oid-intermediate.pem "$work/no-oid-intermediate.key"

rm -f ./*.srl
for pem in root intermediate leaf no-oid-leaf no-oid-intermediate no-oid-intermediate-leaf; do
    printf '%-26s %s\n' "$pem.pem" "$(openssl x509 -in "$pem.pem" -noout -fingerprint -sha1 | cut -d= -f2 | tr -d : | tr 'A-F' 'a-f')"
done
