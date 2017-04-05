<?php 
    class Sitemap extends Modules {
        public function __init() {
            if (!isset($_SERVER["DOCUMENT_ROOT"]))
                cancel_module("sitemap", __("Sitemap module cannot determine the server's document root.", "sitemap"));

            $this->addAlias("add_post", "make_sitemap", 8);
            $this->addAlias("update_post", "make_sitemap", 8);
        }

        static function __install() {
            Config::current()->set("module_sitemap",
                                   array("blog_changefreq" => "daily",
                                         "pages_changefreq" => "yearly",
                                         "posts_changefreq" => "monthly"));
        }

        static function __uninstall() {
            Config::current()->remove("module_sitemap");
        }

        public function settings_nav($navs) {
            if (Visitor::current()->group->can("change_settings"))
                $navs["sitemap_settings"] = array("title" => __("Sitemap", "sitemap"));

            return $navs;
        }

        public function admin_sitemap_settings($admin) {
            $config = Config::current();

            if (!Visitor::current()->group->can("change_settings"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to change settings."));

            if (empty($_POST))
                return $admin->display("pages".DIR."sitemap_settings",
                                       array("changefreq" => array("hourly"  => __("Hourly", "sitemap"),
                                                                   "daily"   => __("Daily", "sitemap"),
                                                                   "weekly"  => __("Weekly", "sitemap"),
                                                                   "monthly" => __("Monthly", "sitemap"),
                                                                   "yearly"  => __("Yearly", "sitemap"),
                                                                   "never"   => __("Never", "sitemap"))));

            if (!isset($_POST['hash']) or $_POST['hash'] != token($_SERVER['REMOTE_ADDR']))
                show_403(__("Access Denied"), __("Invalid security key."));

            fallback($_POST['blog_changefreq'], "daily");
            fallback($_POST['pages_changefreq'], "yearly");
            fallback($_POST['posts_changefreq'], "monthly");

            $config->set("module_sitemap",
                         array("blog_changefreq" => $_POST['blog_changefreq'],
                               "pages_changefreq" => $_POST['pages_changefreq'],
                               "posts_changefreq" => $_POST['posts_changefreq']));

            Flash::notice(__("Settings updated."), "sitemap_settings");
        }

        /**
         * Function: make_sitemap
         * Generates a sitemap of the blog and writes it to the document root.
         */
        public function make_sitemap() {
            if ($this->cancelled)
                return;

            $results = SQL::current()->select("posts",
                                             "posts.id",
                                             array("posts.status" => "public"),
                                             array("posts.id DESC"),
                                             array())->fetchAll();

            $ids = array();

            foreach ($results as $result)
                $ids[] = $result["id"];

            if (!empty($ids))
                fallback($posts, Post::find(array("where" => array("id" => $ids))));
            else
                $posts = array();

            if (!is_array($posts))
                $posts = $posts->paginated;

            $pages = Page::find(array("where" => array("show_in_list" => true),
                                      "order" => "list_order ASC"));

            $config = Config::current();
            $settings = $config->module_sitemap;

            $xml = "<?xml version='1.0' encoding='UTF-8'?>".PHP_EOL;
            $xml.= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

            $xml.= "  <url>\n".
                   "    <loc>".$config->url."/</loc>\n".
                   "    <lastmod>".$posts[0]->updated_at."</lastmod>\n".
                   "    <changefreq>".$settings["blog_changefreq"]."</changefreq>\n".
                   "  </url>\n".
                   "  <url>\n".
                   "    <loc>".url("archive/", MainController::current())."</loc>\n".
                   "    <changefreq>".$settings["blog_changefreq"]."</changefreq>\n".
                   "  </url>\n";

            foreach ($posts as $post) {
                $updated = ($post->updated) ? $post->updated_at : $post->created_at ;
                $priority = ($post->pinned) ? "    <priority>1.0</priority>\n" : "" ;

                $xml.= "  <url>\n".
                       "    <loc>".$post->url()."</loc>\n".
                       "    <lastmod>".$updated."</lastmod>\n".
                       "    <changefreq>".$settings["posts_changefreq"]."</changefreq>\n".$priority.
                       "  </url>\n";
            }

            foreach ($pages as $page) {
                $updated = ($page->updated) ? $page->updated_at : $page->created_at ;

                $xml.= "  <url>\n".
                       "    <loc>".$page->url()."</loc>\n".
                       "    <lastmod>".$updated."</lastmod>\n".
                       "    <changefreq>".$settings["pages_changefreq"]."</changefreq>\n".
                       "  </url>\n";
            }

            $xml.= "</urlset>";

            @file_put_contents($_SERVER["DOCUMENT_ROOT"].DIR."sitemap.xml", $xml);
        }
    }
