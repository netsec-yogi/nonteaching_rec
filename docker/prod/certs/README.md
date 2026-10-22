Place the TLS certificate chain and private key here as `fullchain.pem` and `privkey.pem`
(or point `TLS_CERT_DIR` in `.env` at another directory). Never commit them; this folder
ignores everything except this note. The key should be readable only by root (`chmod 600`).
