fastcgi_cache_path /cache/caseantiques.com levels=1:2 keys_zone=caseantiques.com:100m inactive=1d; # Managed by SpinupWP
include sites-available/caseantiques.com/before/*; # Managed by SpinupWP

server {
	listen 443 ssl; # Managed by SpinupWP
	listen [::]:443 ssl; # Managed by SpinupWP
	http2 on; # Managed by SpinupWP

	server_name caseantiques.com; # Managed by SpinupWP

        ssl_certificate /etc/letsencrypt/live/caseantiques.com/fullchain.pem; # Managed by SpinupWP
        ssl_certificate_key /etc/letsencrypt/live/caseantiques.com/privkey.pem; # Managed by SpinupWP

	root /sites/caseantiques.com/files/web/;

	index index.html index.php;

	access_log /sites/caseantiques.com/logs/access.log;
	error_log /sites/caseantiques.com/logs/error.log;

	# Don't allow pages to be rendered in an iframe on external domains.
	add_header X-Frame-Options "SAMEORIGIN";

	# MIME sniffing prevention
	add_header X-Content-Type-Options "nosniff";

	# Enable cross-site scripting filter in supported browsers.
	add_header X-Xss-Protection "1; mode=block";

	# Limit referrer information sent with requests to third parties.
	add_header Referrer-Policy "strict-origin-when-cross-origin";

	include sites-available/caseantiques.com/server/*; # Managed by SpinupWP

	# Prevent access to hidden files
	location ~* /\.(?!well-known\/) {
		deny all;
	}

	# Prevent access to certain file extensions
	location ~ \.(ini|log|conf|blade.php)$ {
		deny all;
	}

	location / {
		try_files $uri $uri/ /index.php?$args;
	}

	location ~ \.php$ {
		try_files $uri =404;

		include fastcgi.conf;
		fastcgi_pass unix:/run/php/php8.3-caseantiques.sock;

		include sites-available/caseantiques.com/location/*; # Managed by SpinupWP
	}
}

include sites-available/caseantiques.com/after/*; # Managed by SpinupWP
