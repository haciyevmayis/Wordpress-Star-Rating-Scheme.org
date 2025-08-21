<?php

/*-----------------------------------------------------------------------------------*/
# Kavkaz Ten Star Rating - SVG version with admin default
/*-----------------------------------------------------------------------------------*/
class Kavkaz_Ten_Star_Rating_JSON {
    public function __construct() {
        add_shortcode('ten_star_rating', [$this, 'render_rating']);
        add_action('wp_ajax_tsr_save_rating', [$this, 'save_rating']);
        add_action('wp_ajax_nopriv_tsr_save_rating', [$this, 'save_rating']);
        add_action('wp_head', [$this, 'output_schema_json']); // JSON-LD head
    }

    private function user_id_or_ip() {
        return is_user_logged_in() ? 'user_' . get_current_user_id() : 'ip_' . $_SERVER['REMOTE_ADDR'];
    }

    // Head içine JSON-LD ekle
	public function output_schema_json() {
		if ( ! is_singular('post') ) return;

		global $post;
		$post_id = $post->ID;

		$imdb_data   = get_post_meta($post_id, 'imdb', true) ?: 9.0;
		$rating_data = get_post_meta($post_id, 'tsr_rating_data', true);
		$rating_data = $rating_data ? json_decode($rating_data, true) : ['total'=>0,'count'=>0,'users'=>[]];

		$total_votes = $rating_data['count'];      
		$total_score = $rating_data['total'];      

		// Ortalama = adminin başlangıç oy + kullanıcı toplam oyları / (1 + kullanıcı oy sayısı)
		$average_rating = ($imdb_data + $total_score) / ($total_votes + 1);
		$average_rating = max(1, min(10, $average_rating));

		$rating_count_for_schema = $total_votes + 1; // admin oyunu dahil
		$image = get_the_post_thumbnail_url($post_id, 'full') ?: '';
		$url   = get_permalink($post_id);

		// JSON verisini dizi olarak hazırla
		$data = [
			"@context" => "https://schema.org",
			"@type"    => "Movie",
			"name"     => get_the_title($post_id),
			"image"    => $image,
			"url"      => $url,
			"description" => get_the_excerpt($post_id),
			"aggregateRating" => [
				"@type" => "AggregateRating",
				"ratingValue" => number_format($average_rating, 1),
				"ratingCount" => intval($rating_count_for_schema),
				"bestRating"  => "10",
				"worstRating" => "1"
			],
			"publisher" => [
				"@type" => "Organization",
				"name"  => "Film izle",
				"logo"  => [
					"@type" => "ImageObject",
					"url"   => esc_url(kavkaz_get_option('logo'))
				]
			]
		];

		// JSON-LD çıktısı, tüm karakterler güvenli şekilde encode ediliyor
		echo '<script type="application/ld+json">' . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . '</script>';
	}

    // Shortcode render
    public function render_rating($atts) {
        global $post;
        $post_id = $post->ID;

        $imdb_data   = get_post_meta($post_id, 'imdb', true) ?: 9.0;
        $rating_data = get_post_meta($post_id, 'tsr_rating_data', true);
        $rating_data = $rating_data ? json_decode($rating_data, true) : ['total'=>0,'count'=>0,'users'=>[]];

        $user_key    = $this->user_id_or_ip();
        $last_rating = isset($rating_data['users'][$user_key]) ? $rating_data['users'][$user_key] : 0;

        $total_votes = $rating_data['count'];
        $total_score = $rating_data['total'];

        $average_rating = ($imdb_data + $total_score) / ($total_votes + 1);
        $average_rating = max(1, min(10, $average_rating));

        $display_count = $total_votes + 1;

        ob_start();
        ?>
        <div class="tsr-container" data-post="<?php echo esc_attr($post_id); ?>" data-selected="<?php echo $last_rating; ?>">
            <div class="tsr-stars">
                <?php
                for ($i = 1; $i <= 10; $i++) {
                    $fill = ($last_rating && $i <= $last_rating) ? '#f5a623' : (($last_rating==0 && $i <= floor($average_rating)) ? '#f5a623' : '#c7c7c7');
                    echo '<svg class="tsr-star" data-value="' . $i . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24">
                            <polygon points="12,2 15,10 23,10 17,15 19,23 12,18 5,23 7,15 1,10 9,10" fill="' . $fill . '"/>
                          </svg>';
                }
                ?>
            </div>
            <div class="tsr-info">
                Ortalama: <?php echo number_format($average_rating, 1); ?> / 10
                (<?php echo $display_count; ?> oy)
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.tsr-star').on('mouseenter', function(){
                var val = $(this).data('value');
                $(this).parent().find('.tsr-star polygon').each(function(){
                    $(this).attr('fill', $(this).parent().data('value') <= val ? '#ff0000' : '#c7c7c7');
                });
            }).on('mouseleave', function(){
                var container = $(this).closest('.tsr-container');
                var selected = container.data('selected') || 0;
                container.find('.tsr-star polygon').each(function(){
                    $(this).attr('fill', $(this).parent().data('value') <= selected ? '#f5a623' : '#c7c7c7');
                });
            });

            $('.tsr-star').on('click', function(){
                var value = $(this).data('value');
                var container = $(this).closest('.tsr-container');
                var post_id = container.data('post');

                $.post('<?php echo admin_url("admin-ajax.php"); ?>', {
                    action: 'tsr_save_rating',
                    post_id: post_id,
                    rating: value
                }, function(response){
                    if(response.success){
                        var avg = parseFloat(response.data.avg);
                        var count = response.data.count;
                        container.data('selected', value);

                        container.find('.tsr-star polygon').each(function(){
                            $(this).attr('fill', $(this).parent().data('value') <= value ? '#f5a623' : '#c7c7c7');
                        });

                        var displayCount = count + 1; // admin oyunu dahil
                        container.find('.tsr-info').html("Ortalama: " + avg.toFixed(1) + " / 10 (" + displayCount + " oy)");
                    } else {
                        alert(response.data.message);
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    // AJAX kaydetme
    public function save_rating() {
        $post_id  = intval($_POST['post_id']);
        $rating   = floatval($_POST['rating']);
        $user_key = $this->user_id_or_ip();

        $rating_data = get_post_meta($post_id, 'tsr_rating_data', true);
        $rating_data = $rating_data ? json_decode($rating_data, true) : ['total'=>0,'count'=>0,'users'=>[]];

        if (isset($rating_data['users'][$user_key])) {
            wp_send_json_error(['message'=>'Sadece bir kez oy verebilirsiniz.']);
        }

        if ($rating < 1 || $rating > 10) {
            wp_send_json_error(['message'=>'Geçersiz oy değeri']);
        }

        $rating_data['total'] += $rating;
        $rating_data['count'] += 1;
        $rating_data['users'][$user_key] = $rating;

        update_post_meta($post_id, 'tsr_rating_data', json_encode($rating_data));

        $imdb_data = get_post_meta($post_id, 'imdb', true) ?: 9.0;
        $average_rating = ($imdb_data + $rating_data['total']) / ($rating_data['count'] + 1);
        $average_rating = max(1, min(10, $average_rating));

        wp_send_json_success([
            'avg'   => $average_rating,
            'count' => $rating_data['count']
        ]);
    }
}

new Kavkaz_Ten_Star_Rating_JSON();
