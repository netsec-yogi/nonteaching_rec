# Shared by both site variants. Requests handed to index.php get the full header set
# (CSP, HSTS, Referrer-Policy) from the application's SecurityHeaders middleware.
root /var/www/html/public;
index index.php;
charset utf-8;
client_max_body_size 25m;
server_tokens off;

location / {
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "same-origin" always;
    try_files $uri $uri/ /index.php?$query_string;
}

# Fingerprinted Vite assets never change.
location /build/ {
    add_header X-Content-Type-Options "nosniff" always;
    add_header Cache-Control "public, max-age=31536000, immutable" always;
    try_files $uri =404;
}

location = /theme-init.js { add_header Cache-Control "no-cache" always; try_files $uri =404; }
location = /favicon.ico { access_log off; log_not_found off; }
location = /robots.txt  { access_log off; log_not_found off; }

# Only the front controller reaches PHP.
location = /index.php {
    fastcgi_pass app:9000;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    fastcgi_param DOCUMENT_ROOT $realpath_root;
    fastcgi_param HTTPS $ntrec_https;
    fastcgi_read_timeout 120;
    fastcgi_buffers 16 16k;
    fastcgi_buffer_size 32k;
    include fastcgi_params;
}
location ~ \.php$ { return 404; }
location ~ /\.(?!well-known).* { deny all; }
