#!/usr/bin/env bash
server="$1"
domain="$2"
webroot="$3"
name="$4"
webconfig="$5"
share="$6"
ip="$7"
alias="$8 $9 ${10} ${11} ${12} ${13} ${14} ${15} ${16}"
if [ "$share" == "1" ]; then
    alias="$alias *.vagrantcloud.com"
fi

root="/home/vagrant/$name"
webroot="$root/$webroot"

if [ "$server" == "nginx" ]; then
    block="server {
        listen $ip:80;
        fastcgi_read_timeout 600;
        server_name $domain $alias;
        root $webroot;

        location / {
            index index.html index.php; ## Allow a static html file to be shown first
            try_files \$uri \$uri/ @handler; ## If missing pass the URI to Magento's front handler
            expires 30d; ## Assume all files are cachable
        }

        ## These locations would be hidden by .htaccess normally
        location ^~ /app/                { deny all; }
        location ^~ /includes/           { deny all; }
        location ^~ /lib/                { deny all; }
        location ^~ /media/downloadable/ { deny all; }
        location ^~ /pkginfo/            { deny all; }
        location ^~ /report/config.xml   { deny all; }
        location ^~ /var/                { deny all; }

        location /var/export/ { ## Allow admins only to view export folder
            auth_basic           "Restricted"; ## Message shown in login window
            auth_basic_user_file htpasswd; ## See /etc/nginx/htpassword
            autoindex            on;
        }

        location  /. { ## Disable .htaccess and other hidden files
            return 404;
        }

        location @handler { ## Magento uses a common front handler
            rewrite / /index.php;
        }

        location ~ .php/ { ## Forward paths like /js/index.php/x.js to relevant handler
            rewrite ^(.*.php)/ \$1 last;
        }

        location ~ .php\$ { ## Execute PHP scripts
            if (!-e \$request_filename) { rewrite / /index.php last; } ## Catch 404s that try_files miss

            expires        off; ## Do not cache dynamic content
            fastcgi_pass 127.0.0.1:9000;
            fastcgi_param  SCRIPT_FILENAME  \$document_root\$fastcgi_script_name;
            fastcgi_param  MAGE_RUN_CODE default; ## Store code is defined in administration > Configuration > Manage Stores
            fastcgi_param  MAGE_RUN_TYPE store;
            include        fastcgi_params; ## See /etc/nginx/fastcgi_params
        }

        error_log /vagrant/logs/${domain}_error.log;
        access_log /vagrant/logs/${domain}_access.log;
    }
    "

    # Create nginx site configuration
    echo "$block" > "/etc/nginx/sites-available/$domain"
fi
