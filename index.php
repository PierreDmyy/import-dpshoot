<?php
/**
 * Plugin Name: Importateur Photo WooCommerce – DP Shoot (File Queue)
 * Description: Crée des produits WooCommerce depuis /uploads/import/, avec watermark sur l'image affichée, l'originale en téléchargement. 5 images toutes les 5 secondes.
 * Version: 1.2
 * Author: ChatGPT
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page('Importateur Photos', 'Importer Photos', 'manage_woocommerce', 'importateur_photo_dpshoot', 'dp_importer_admin_page', 'dashicons-images-alt2', 58);
});

function dp_importer_admin_page() {
    echo '<div class="wrap"><h1>Importateur WooCommerce – DP SHOOT</h1>';

    $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
    if (empty($terms)) {
        echo '<p style="color:red;">⚠️ Aucune catégorie produit trouvée. Merci d’en créer une dans WooCommerce.</p></div>';
        return;
    }

    $upload_dir = wp_upload_dir();
    $import_path = $upload_dir['basedir'] . '/import/';
    $import_url = $upload_dir['baseurl'] . '/import/';
    $images = glob($import_path . '*.jpg');

    echo '<form method="post" id="dp-import-form">';
    echo '<label for="event_cat">Sélectionnez la catégorie :</label><br>';
    echo '<select name="event_cat" id="event_cat">';
    foreach ($terms as $term) {
        echo '<option value="' . esc_attr($term->term_id) . '">' . esc_html($term->name) . '</option>';
    }
    echo '</select><br><br>';
    echo '<p><strong>Type de vente :</strong></p>';
    echo '<label><input type="radio" name="payant" value="1" checked> Payantes (15 €)</label><br>';
    echo '<label><input type="radio" name="payant" value="0"> Gratuites</label><br><br>';
    echo '<label><input type="checkbox" name="remplacer" value="1"> Remplacer les produits existants</label><br><br>';

    echo '<p><strong>Images à importer :</strong></p>';
    if (empty($images)) {
        echo '<p style="color:red;">Aucune image trouvée dans /uploads/import/</p>';
    } else {
        foreach ($images as $img) {
            $filename = basename($img);
            echo '<label><input type="checkbox" name="selected_images[]" value="' . esc_attr($filename) . '"> ' . esc_html($filename) . '</label><br>';
        }
    }

    echo '<br><button type="button" class="button button-primary" onclick="startImport()">Démarrer l\'importation</button>';
    echo '</form><div id="dp-import-log" style="margin-top:20px;"></div>';

    if (!empty($images)) {
        $ajax_url = admin_url('admin-ajax.php');
        echo "<script>
        function startImport() {
            const form = document.getElementById('dp-import-form');
            const formData = new FormData(form);
            const selected = formData.getAll('selected_images[]');
            if (selected.length === 0) {
                alert('Veuillez sélectionner au moins une image.');
                return;
            }
            const cat = formData.get('event_cat');
            const payant = formData.get('payant');
            const remplacer = formData.get('remplacer') ? 1 : 0;
            let index = 0;

            function importerBatch() {
                const batch = selected.slice(index, index + 5);
                if (batch.length === 0) {
                    document.getElementById('dp-import-log').innerHTML += '<br>[END] Importation terminée.';
                    return;
                }
                const data = new FormData();
                data.append('action', 'dp_import_batch');
                data.append('cat', cat);
                data.append('payant', payant);
                data.append('remplacer', remplacer);
                batch.forEach(img => data.append('images[]', img));

                fetch('{$ajax_url}', {
                    method: 'POST',
                    body: data
                })
                .then(res => res.text())
                .then(txt => {
                    document.getElementById('dp-import-log').innerHTML += '<br>' + txt;
                    index += 5;
                    setTimeout(importerBatch, 5000);
                });
            }
            document.getElementById('dp-import-log').innerHTML = 'Importation en cours...';
            importerBatch();
        }
        </script>";
    }

    echo '</div>';
}

add_action('wp_ajax_dp_import_batch', function () {
    $cat_id = isset($_POST['cat']) ? (int) $_POST['cat'] : 0;
    $is_payant = isset($_POST['payant']) ? (int) $_POST['payant'] : 1;
    $remplacer = isset($_POST['remplacer']) ? (int) $_POST['remplacer'] : 0;
    $images = isset($_POST['images']) ? $_POST['images'] : array();

    $upload_dir = wp_upload_dir();
    $import_path = $upload_dir['basedir'] . '/import/';
    $import_url = $upload_dir['baseurl'] . '/import/';
    $generated_path = $upload_dir['basedir'] . '/generated/';
    if (!file_exists($generated_path)) wp_mkdir_p($generated_path);

    foreach ($images as $filename) {
        $img_path = $import_path . basename($filename);
        if (!file_exists($img_path)) {
            echo "❌ Fichier introuvable : " . esc_html($filename) . "<br>";
            continue;
        }

        echo "📷 Image trouvée : " . esc_html($filename) . "<br>";
        $name = pathinfo($filename, PATHINFO_FILENAME);

        $existing = get_page_by_title($name, OBJECT, 'product');
        if ($existing && !$remplacer) {
            echo "⚠️ Produit déjà existant : " . esc_html($name) . "<br>";
            continue;
        } elseif ($existing && $remplacer) {
            wp_delete_post($existing->ID, true);
            echo "♻️ Produit existant supprimé : " . esc_html($name) . "<br>";
        }

        $image = imagecreatefromjpeg($img_path);
        if (!$image) {
            echo "❌ Erreur de lecture de l'image : " . esc_html($filename) . "<br>";
            continue;
        }
        $w = imagesx($image);
        $h = imagesy($image);

        $logo_path = plugin_dir_path(__FILE__) . 'logo.png';
        if (file_exists($logo_path)) {
            $logo = @imagecreatefrompng($logo_path);
            if ($logo) {
                imagealphablending($image, true);
                imagesavealpha($image, true);
                imagealphablending($logo, true);
                imagesavealpha($logo, true);
                $logo_w = imagesx($logo);
                $logo_h = imagesy($logo);
                $dst_x = ($w - $logo_w) / 2;
                $dst_y = ($h - $logo_h) / 2;
                imagecopy($image, $logo, $dst_x, $dst_y, 0, 0, $logo_w, $logo_h);
                imagedestroy($logo);
                echo "✅ Logo appliqué<br>";
            }
        }

        $text_color = imagecolorallocatealpha($image, 255, 255, 255, 90);
        for ($y = 0; $y < $h; $y += 150) {
            for ($x = 0; $x < $w; $x += 300) {
                imagestring($image, 16, $x, $y, 'DP SHOOT', $text_color);
            }
        }
        echo "✅ Texte filigrane appliqué<br>";

        $filigrane_path = $generated_path . $filename;
        imagejpeg($image, $filigrane_path);
        imagedestroy($image);
        echo "✅ Image enregistrée<br>";

        $upload_file = array('name' => $filename, 'tmp_name' => $filigrane_path);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attach_id = media_handle_sideload($upload_file, 0);
        if (is_wp_error($attach_id)) {
            echo "❌ Erreur lors de l'envoi de l'image : " . esc_html($filename) . "<br>";
            continue;
        }
        echo "✅ Image envoyée dans la médiathèque<br>";

        $product = new WC_Product_Simple();
        $product->set_name($name);
        if ($is_payant) {
            $product->set_price(15.00);
            $product->set_regular_price(15.00);
        }
        $product->set_virtual(true);
        $product->set_downloadable(true);
        $product->set_image_id($attach_id);
        $product->set_downloads(array(
            'file' => array(
                'name' => $name . ' HD',
                'file' => $import_url . $filename
            )
        ));
        $product->set_category_ids(array($cat_id));
        $product->save();
        echo "✅ Produit WooCommerce créé<br>";

        unlink($img_path);
        echo "🗑️ Image originale supprimée<br>";
    }

    wp_die();
});
?>
