solodrive.pro = WP insall - main marketing, publlic facing website
app.solodrive.pro = WP install - user auth, system UI shell
[tenant].solodrive.pro = provides unique link to tenant storefront, used to resolve tenant_id (mike.solodrive.pro) and forward to app.solodrive.pro/[storefront], retains referrer url to resolve tenant_id. After form submission publilc <token> minted for visitor and written to leadCPT with tenant_id. token becomes public identifier, tenant_id and lead_id own user interfaces.




Hostinger file structure:
[u995421351@us-bos-web1711 domains]$ dir
sogacapital.com  soga-go.com  soga-studio.com  solodrive.pro
[u995421351@us-bos-web1711 solodrive.pro]$ cd public_html
[u995421351@us-bos-web1711 public_html]$ dir
alpha        index.php        wp-admin              wp-cron.php        wp-settings.php
app          license.txt      wp-blog-header.php    wp-includes        wp-signup.php
apps         mike             wp-comments-post.php  wp-links-opml.php  wp-trackback.php
beta         public_html      wp-config.php         wp-load.php        xmlrpc.php
default.php  readme.html      wp-config-sample.php  wp-login.php
git-deploy   wp-activate.php  wp-content            wp-mail.php
[u995421351@us-bos-web1711 public_html]$ cd app
[u995421351@us-bos-web1711 app]$ dir
default.php          wp-admin              wp-cron.php        wp-settings.php
default.php.old.php  wp-blog-header.php    wp-includes        wp-signup.php
index.php            wp-comments-post.php  wp-links-opml.php  wp-trackback.php
license.txt          wp-config.php         wp-load.php        xmlrpc.php
readme.html          wp-config-sample.php  wp-login.php
wp-activate.php      wp-content            wp-mail.php
[u995421351@us-bos-web1711 app]$ cd wp-content
[u995421351@us-bos-web1711 wp-content]$ dir
debug.log  index.php  litespeed  mu-plugins  plugins  themes  upgrade  uploads
[u995421351@us-bos-web1711 wp-content]$ cd plugins
[u995421351@us-bos-web1711 plugins]$ dir
contact-form-7  index.php  solodrive-kernel
[u995421351@us-bos-web1711 plugins]$ 

Domains

Sub-domains

DNS