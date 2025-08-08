<?php

/*
*
* @package Yariko
*
*/

namespace Cbf\Inc\Base;

use Cbf\Inc\Services\HubspotService;

class Ajax{

    public function register(){

        /**
         * All ajax actions
         */
        add_action( 'wp_ajax_add_recipe', array($this, 'addRecipe') );
        add_action( 'wp_ajax_nopriv_add_recipe', array($this, 'addRecipe') );
        add_action( 'wp_ajax_add_photo', array($this, 'AddPhoto') );
        add_action( 'wp_ajax_nopriv_photo', array($this, 'AddPhoto') );

        add_action( 'wp_ajax_get_recipe_categories', array($this, 'getRecipeCategories') );
        add_action( 'wp_ajax_nopriv_get_recipe_categories', array($this, 'getRecipeCategories') );

        add_action( 'wp_ajax_get_your_recipes', array($this, 'getYourRecipes') );
        add_action( 'wp_ajax_nopriv_get_your_recipes', array($this, 'getYourRecipes') );

        add_action( 'wp_ajax_get_recipe', array($this, 'getRecipe') );
        add_action( 'wp_ajax_nopriv_get_recipe', array($this, 'getRecipe') );

	    add_action( 'wp_ajax_delete_recipe', array($this, 'deleteRecipe') );
	    add_action( 'wp_ajax_nopriv_delete_recipe', array($this, 'deleteRecipe') );

        add_action( 'wp_ajax_get_cookbook', array($this, 'getCookbookById') );
        add_action( 'wp_ajax_nopriv_get_cookbook', array($this, 'getCookbookById') );

        add_action( 'wp_ajax_add_cookbook', array($this, 'addCookbook') );
        add_action( 'wp_ajax_nopriv_add_cookbook', array($this, 'addCookbook') );

        add_action( 'wp_ajax_get_user_cookbooks', array($this, 'getUserCookbooks') );
        add_action( 'wp_ajax_nopriv_get_user_cookbooks', array($this, 'getUserCookbooks') );

        add_action( 'wp_ajax_add_collaborator', array($this, 'addCollaborator') );
        add_action( 'wp_ajax_nopriv_add_collaborator', array($this, 'addCollaborator') );

        add_action( 'wp_ajax_get_collaborators', array($this, 'getCollaborators') );
        add_action( 'wp_ajax_nopriv_get_collaborators', array($this, 'getCollaborators') );

        add_action( 'wp_ajax_remove_collaborator', array($this, 'removeCollaborator') );
        add_action( 'wp_ajax_nopriv_remove_collaborator', array($this, 'removeCollaborator') );

        add_action( 'wp_ajax_publish_cookbook', array($this, 'publishCookbook') );
        add_action( 'wp_ajax_nopriv_publish_cookbook', array($this, 'publishCookbook') );

        add_action( 'wp_ajax_get_templates', array($this, 'getTemplates') );
        add_action( 'wp_ajax_nopriv_get_templates', array($this, 'getTemplates') );

        add_action( 'wp_ajax_get_services', array($this, 'getServices') );
        add_action( 'wp_ajax_nopriv_get_services', array($this, 'getServices') );

	    add_action( 'wp_ajax_get_countries', array($this, 'getCountries') );
	    add_action( 'wp_ajax_nopriv_get_countries', array($this, 'getCountries') );

	    add_action( 'wp_ajax_get_units', array($this, 'getUnits') );
	    add_action( 'wp_ajax_nopriv_get_units', array($this, 'getUnits') );

	    /**
	     * Share Recipe by email
	     */
	    add_action( 'wp_ajax_share_recipe', array($this, 'shareRecipe') );
	    add_action( 'wp_ajax_nopriv_share_recipe', array($this, 'shareRecipe') );

        /**
         * Generate cookbook xml files
         */
        add_action( 'wp_ajax_generate_xml_files', array($this, 'generate_XML_files') );
        add_action( 'wp_ajax_nopriv_generate_xml_files', array($this, 'generate_XML_files') );

	    /**
	     * Generate the CSV files
	     */
	    add_action( 'wp_ajax_generate_csv_files', array($this, 'generateCsvFiles') );
	    add_action( 'wp_ajax_nopriv_generate_csv_files', array($this, 'generateCsvFiles') );

        /**
         * Send Comment
         */
	    add_action( 'wp_ajax_add_comment', array($this, 'addComment') );
	    add_action( 'wp_ajax_nopriv_add_comment', array($this, 'addComment') );

	    /**
	     * Get All Comments
	     */
	    add_action( 'wp_ajax_get_comments', array($this, 'getComments') );
	    add_action( 'wp_ajax_nopriv_get_comments', array($this, 'getComments') );

	    /**
	     * Enroll customer to
	     */
	    add_action( 'woocommerce_order_status_completed', array($this,'enroll_customer_after_publishing'), 10, 1 );

	    /**
	     * Get Accounts
	     */
	    add_action( 'wp_ajax_get_accounts', array($this, 'getAccounts') );
	    add_action( 'wp_ajax_nopriv_get_accounts', array($this, 'getAccounts') );

	    /**
	     * Select Account
	     */
	    add_action( 'wp_ajax_select_account', array($this, 'selectAccount') );
	    add_action( 'wp_ajax_nopriv_select_account', array($this, 'selectAccount') );
    }

	/**
	 * Select account
	 */
    function selectAccount(){
    	$user_id = $_POST['user_id'];
    	$email = $_POST['email'];
    	$username = $_POST['username'];
    	$collaborator_id = $_POST['collaborator_id'];
    	$account_type = $_POST['account_type'];
    	$premium = $_POST['premium'];

	    $user = wp_get_current_user();


	    $account_selected = array(
		    'id' => $user_id,
		    'email' => $email,
		    'account_type' => $account_type,
		    'collaborator_id' => $collaborator_id,
		    'username' => $username,
		    'premium' => $premium,
	    );

	    update_user_meta($user->ID,'account_selected',serialize($account_selected) );

	    echo json_encode(array('success'=> true, 'selection' => $account_selected));
	    wp_die();
    }

	/**
	 * Get all the user accounts
	 */
    function getAccounts(){


	    $user = wp_get_current_user();

	    $account_selected = null;

	    $accounts = getAccountsByUserId($user->ID);

		if($selection = get_user_meta($user->ID, 'account_selected', true)){
			$account_selected = unserialize($selection);
			$customer = rcp_get_customer_by_user_id($account_selected['id']);
			$premium = false;
			if ($customer) {
				$memberships = $customer->get_memberships();
				$premium = $memberships[0]->get_gateway() == 'free' || $memberships[0]->get_status() == 'cancelled' ? false : true;
			}
			$account_selected['premium'] = $premium;
		}else{
			$account_selected = count($accounts) > 0 ?  $accounts[0] : null;
		}


	    echo json_encode(array('success'=> true, 'accounts' => $accounts, 'selection' => $account_selected));
	    wp_die();
    }

	/**
	 * Get all comments by ID
	 */
	function getComments(){
		//$admin = $_POST['admin'];
        if(!isset($_POST['cookbook_id'])){
            wp_die();
        }
		$cookbook_id = $_POST['cookbook_id'];

		$comments = getCookbookComments($cookbook_id);

		echo json_encode(array('success'=> true, 'comments' => $comments));
		wp_die();
	}

	/**
	 * Adding a comment on single order chat
	 */
	function addComment(){

		$admin = $_POST['admin'];
		$cookbook_id = $_POST['cookbook_id'];
		$comment = $_POST['comment'];

		insertCommentCookbook($admin,$comment,$cookbook_id);

		echo json_encode(array('success'=> true, 'post' => $_POST));
		wp_die();
	}

    /**
     * Generate XMl files
     */
   // function generate_XML_files(){
    function generate_XMLDISCOUNTINUED_files(){

        $cookbook_id = $_POST['cookbook_id'];
        $order_id = $_POST['order_id'];

        if(get_post_meta($order_id,'zip_file_generated', true)){
            $zip_file_name = wp_upload_dir()['basedir'] . '/zips/' . get_post_meta($order_id, 'zip_file_name', true);
            if(file_exists($zip_file_name)){
                unlink($zip_file_name);
            }

            update_post_meta($order_id,'zip_file_generated', false);
        }

        if($cookbook_id){

            $recipes = get_field( 'recipes',$cookbook_id );
            $recipe_images = array();

            //Getting images from recipes
            if(is_array($recipes) && count($recipes) > 0){
                foreach ($recipes as $recipe_id){
                    $recipe = get_post($recipe_id);
                    $images = get_field( 'cbf_photos',$recipe_id );
                    foreach ($images as $image){
                        $path = get_attached_file($image['image']['id']);
                        $path_array = explode('.',$path);
                        array_push($recipe_images, array(
                            'id' => $image['image']['id'],
                            'path' => $path,
                            'recipe_id' => $recipe_id,
                            'type' => $path_array[1],
                            'recipe_name' => $recipe->post_name
                        ));
                    }
                }
            }


            $zip = new \ZipArchive();

            $upload_dir = wp_upload_dir();

            $file_name = 'cookbook_' . $cookbook_id . '_'. time() .'.zip';

            $zip_file_name = wp_upload_dir()['basedir'] . '/zips/' . $file_name;

            $file_route = '/zips/'. $file_name;

            $url_file = $upload_dir['baseurl'] . $file_route;

            $zip->open($zip_file_name, \ZipArchive::CREATE);

            //Appending Back cover
            $back_image = get_field( 'back_cover_image',$cookbook_id ) ? get_field( 'back_cover_image',$cookbook_id ) : null;
            if($back_image){
                $path = get_attached_file($back_image['ID']);
                $path_array = explode('.',$path);
                $zip->addFile($path,'cookbook_back_cover' .  '.' . $path_array[1]);
            }

            $cont = 1;
            foreach($recipe_images as $file){

                $zip->addFile($file['path'],'recipe_' . $file['recipe_name'] . '_image_' . $cont . '.' . $file['type']);
                $cont++;
            }

            update_post_meta($order_id,'zip_file_url', $url_file);
            update_post_meta($order_id,'zip_file_name', $file_name);
            update_post_meta($order_id,'zip_file_generated', true);

            //  $file = wp_upload_bits( 'yes.zip', null, @file_get_contents( $zip->filename ) );

            /**
             * Append recipes xml
             */
            $xml_path = cbf_append_xml_files($zip, $cookbook_id);

            $zip->close();

            /**
             * Remove the xml generated previously
             */
            unlink($xml_path);

            if(file_exists($zip_file_name)){
                echo json_encode(array('success'=> true, 'post' => $_POST));
                wp_die();
            }else{
                echo json_encode(array('success'=> false, 'msg' => 'We could not generate the file.'));
                wp_die();
            }

        }else{
            update_post_meta($order_id,'zip_file_generated', false);
            echo json_encode(array('success'=> false, 'msg' => 'We are not able to generate the files without a cookbook'));
            wp_die();
        }

    }

    function generate_XML_files(){

		$cookbook_id = $_POST['cookbook_id'];
		$order_id = $_POST['order_id'];

	    $order = wc_get_order( $order_id );

		if(get_post_meta($order_id,'zip_file_generated', true)){
			$zip_file_name = wp_upload_dir()['basedir'] . '/zips/' . get_post_meta($order_id, 'zip_file_name', true);
			if(file_exists($zip_file_name)){
				unlink($zip_file_name);
			}

			update_post_meta($order_id,'zip_file_generated', false);
		}

		if($cookbook_id){

			$zip = new \ZipArchive();

			$upload_dir = wp_upload_dir();

			$file_name = 'cookbook_' . $cookbook_id . '_'. time() .'.zip';

			$zip_file_name = wp_upload_dir()['basedir'] . '/zips/' . $file_name;

			$file_route = '/zips/'. $file_name;

			$url_file = $upload_dir['baseurl'] . $file_route;

			$zip->open($zip_file_name, \ZipArchive::CREATE);

			$zip->addEmptyDir('images');

			$back_image = get_field( 'cbf_back_cover_image',$cookbook_id ) ? get_field( 'cbf_back_cover_image',$cookbook_id ) : null;
			$front_image = get_field( 'cbf_front_cover_image',$cookbook_id ) ? get_field( 'cbf_front_cover_image',$cookbook_id ) : -1;
			$introduction_image = get_field( 'cbf_introduction_image',$cookbook_id ) ? get_field( 'cbf_introduction_image',$cookbook_id ) : -1;

			$image_paths = array(
				'back_image' => '',
				'front_image' => '',
				'introduction_image' => '',
			);

			if($back_image){
				$path = get_attached_file($back_image['ID']);
				$image_paths['back_image'] = '\images\\'.$back_image['filename'];
				$path_to_add = 'images/'.$back_image['filename'];
				$zip->addFile($path,$path_to_add);
			}
			if($front_image){
				$path = get_attached_file($front_image['ID']);
				$image_paths['front_image'] = '\images\\'.$front_image['filename'];
				$path_to_add = 'images/'.$front_image['filename'];
				$zip->addFile($path,$path_to_add);
			}
			if($introduction_image){
				$path = get_attached_file($introduction_image['ID']);
				$image_paths['introduction_image'] = '\images\\'.$introduction_image['filename'];
				$path_to_add = 'images/'.$introduction_image['filename'];
				$zip->addFile($path,$path_to_add);
			}

			update_post_meta($order_id,'zip_file_url', $url_file);
			update_post_meta($order_id,'zip_file_name', $file_name);
			update_post_meta($order_id,'zip_file_generated', true);

			/**
			 * Add template path
			 */
			$template_id = get_post_meta( $order->get_id(), 'cbf_template', true );
			$option = get_post_meta( $order->get_id(), 'cbf_option_type', true );
			$template_path = '';
			if($option == 1 && $template_id > 0){
				$template = getTemplateACFByID($template_id);
				$path = get_attached_file($template['image']['id']);
				$template_filename = $template['image']['filename'];

				$zip->addEmptyDir('template');
				$template_path = '\template\\'.$template_filename;
				$path_to_add = 'template/'.$template_filename;
				$zip->addFile($path,$path_to_add);

			}

			/**
			 * Append cookbook csv
			 */
			$cookbook_path = cbf_append_csv_files($zip, $cookbook_id, $image_paths,$order,$template_path);
			$recipes_path = cbf_append_csv_recipes($zip,$cookbook_id);

			$zip->close();

			/**
			 * Remove the csv generated previously
			 */
			unlink($cookbook_path);
			unlink($recipes_path);

			if(file_exists($zip_file_name)){
				echo json_encode(array('success'=> true, 'post' => $_POST));
				wp_die();
			}else{
				echo json_encode(array('success'=> false, 'msg' => 'We could not generate the file.'));
				wp_die();
			}

		}else{
			update_post_meta($order_id,'zip_file_generated', false);
			echo json_encode(array('success'=> false, 'msg' => 'We are not able to generate the files without a cookbook'));
			wp_die();
		}

	}

    /**
     * Get the templates from the option plugin page ACF
     */
    function getTemplates(){
        echo json_encode(array('success'=> 'true', 'templates' => getTemplatesACF()));
        wp_die();
    }

    /**
     * Get the templates from the option plugin page ACF
     */
    function getServices(){
        echo json_encode(array('success'=> 'true', 'services' => getServicesACF()));
        wp_die();
    }

    /**
     * Get user CookBook
     */
    public function getUserCookbooks(){
        $author_id = $_POST['author_id'];

        $cookbooks = getUserCookbook($author_id);

        foreach ($cookbooks as $cookbook){
            $cookbook->state = get_field('state',$cookbook->ID);
        }

        echo json_encode(array('success'=> 'true', 'cookbooks' => $cookbooks));
        wp_die();
    }

    /**he
     * Get Recipe Categories
     */
    public function getRecipeCategories(){
        $terms = get_terms( array(
            'taxonomy' => 'cat_recipe',
            'hide_empty' => false,
        ) );
		$categories = array();
        foreach ($terms as $term){
        	$term->name = str_replace('&amp;', '&', $term->name);
			array_push($categories, $term);
        }


        echo json_encode(array('success'=> 'true', 'categories' => $categories));
        wp_die();
    }

    /**
     * Add a Recipe
     */
    public function addRecipe(){

        $cookbooks_ids = !empty($_POST['cookbooks_ids']) ? explode(',',$_POST['cookbooks_ids']) : [];
        //$ingredients = json_decode(str_replace("\\","",$_POST['ingredients']));
	    $ingredients = $_POST['ingredients'];

	    $photos = json_decode(str_replace("\\","",$_POST['photos']));
	    $story_photos = json_decode(str_replace("\\","",$_POST['story_photos']));
        $title = $_POST['title'];
        $category = $_POST['category'];
        $instructions = $_POST['instructions'];
        $story = $_POST['story'];
        $headline_story = $_POST['headline_story'];
        $author_id = $_POST['author_id'];
        $status = strtolower($_POST['status']);
        $post_id = $_POST['edit'] > 0 ? intval($_POST['edit'] ): -1;
        $new_cook_book = $_POST['new_cookbook'];
        $country = $_POST['country'];
        $type = $_POST['type'];


        if($post_id == -1){
            $post_id  = wp_insert_post( array(
                    'post_title'    => $title ,
                    'post_content'  => '',
                    'post_status'   => $status,
                    'post_type'   => 'recipe',
                    'post_author'   => $author_id,
                )
            );

        }else{
            wp_update_post( array(
                'ID' => $post_id,
                'post_title'    => $title ,
                'post_content'  => $instructions,
                'post_status'   => $status,
            ) );

        }


        if($post_id != 0){

            //Saving category
            if($category != -1){
                $category_inserted = wp_set_object_terms( $post_id, intval($category), 'cat_recipe' );
                if(!$category_inserted){
                    echo json_encode(array('success'=> 'false', 'msg' => 'There was an error when inserting the recipe category'));

                    wp_die();
                }

            }

            /**
             * Adding/Updating the ingredients to ACF
             */
            /*if(count($ingredients) > 0){
                $ingredients_normalized = cbf_normalize_ingredients($ingredients);
                update_field( 'cbf_ingredients', [],$post_id);
                if(!update_field( 'cbf_ingredients', $ingredients_normalized,$post_id)){
                    echo json_encode(array('success'=> 'false', 'msg' => 'The Recipe could not be inserted, error inserting ingredients'));
                }
            }*/
	        update_field( 'cbf_ingredients_text', $ingredients, $post_id);
	        update_field( 'cbf_instructions', $instructions, $post_id);

            /**
             * Adding/Updating the photos to ACF
             */
            if(count($photos) > 0){
                $photos = cbf_normalize_photos($photos);

                update_field( 'cbf_photos', [],$post_id);

                if(!update_field( 'cbf_photos', $photos,$post_id)){
                    echo json_encode(array('success'=> 'false', 'msg' => 'The Recipe could not be inserted, error inserting photos'));
                    wp_die();
                }
            }

	        if(count($story_photos) > 0){
		        $photos = cbf_normalize_photos($story_photos);

		        update_field( 'cbf_story_photos', [],$post_id);

		        if(!update_field( 'cbf_story_photos', $photos,$post_id)){
			        echo json_encode(array('success'=> 'false', 'msg' => 'The Recipe could not be inserted, error inserting photos'));
			        wp_die();
		        }
	        }

            /**
             * Add Story
             */
            update_field( 'story', $story, $post_id);
            update_field( 'cbf_headline_story', $headline_story, $post_id);

	        /**
	         * Update/Add country
	         */
            update_field( 'country_recipe', $country, $post_id);

            /**
             * Add a new Cookbook in case the user select the create a new cookbook on the assign menu
             */
            $cookbook_id = -1;
            if($type == 'new'){
                $cookbook_id  = wp_insert_post( array(
                        'post_title'    => $new_cook_book ,
                        'post_content'  => '',
                        'post_status'   => 'publish',
                        'post_type'   => 'cookbook',
                        'post_author'   => $author_id,
                    )
                );

                $cookbooks_ids[] = $cookbook_id;
            }

            /**
             * Creating the recipe relation with a cookbook
             */
            if(count($cookbooks_ids) > 0){
                insertCookbooksToRecipe($cookbooks_ids, $post_id);
            }

            echo json_encode(array('success'=> true, 'msg' => 'Recipe inserted successfully', 'id' => $post_id, 'cookbook_id' => $cookbook_id));
            wp_die();
        }else{
            echo json_encode(array('success'=> 'false', 'msg' => 'The Recipe could not be inserted'));
            wp_die();
        }
    }

    /**
     * Add a cookbook instance(Cookbook = CPT)
     */
    public function addCookbook(){
        $title = $_POST['title'];
        $author = $_POST['author'];

        $introduction_headline = $_POST['introduction_headline'];
        $introduction = $_POST['introduction'];
        $back_cover_headline = $_POST['back_cover_headline'];
	    $back_cover_story = $_POST['back_cover_story'];
        $dedication = $_POST['dedication'];
        $front_image = $_POST['front_image'];
        $introduction_image = $_POST['introduction_image'];
        $back_image = $_POST['back_image'];
        $author_id = $_POST['author_id'];
        $recipes = $_POST['recipes'];
        $introduction_image_caption = $_POST['introduction_image_caption'];

        $recipes = explode(',', $recipes);

        $post_id = $_POST['edit'] > 0 ? intval($_POST['edit'] ) : -1;

        // var_dump($_POST);exit;

        if($post_id == -1){
            $post_id  = wp_insert_post( array(
                    'post_title'    => $title ,
                    'post_content'  => '',
                    'post_status'   => 'publish',
                    'post_type'   => 'cookbook',
                    'post_author'   => $author_id,

                )
            );

        }else{
            wp_update_post( array(
                'ID' => $post_id,
                'post_title'    => $title,
            ) );

        }

        if(!empty($introduction_image_caption)){
	        update_field( 'cbf_introduction_image_caption', $introduction_image_caption,$post_id);
        }

        update_field( 'cbf_front_cover_image', $front_image,$post_id);
	    update_field( 'cbf_introduction_image', $introduction_image,$post_id);
	    update_field( 'cbf_back_cover_image', $back_image,$post_id);

        //Updating the ACF related to the new/updated cookbook

        update_field( 'cbf_author_name', $author,$post_id);
        update_field( 'dedication', $dedication,$post_id);
        update_field( 'cbf_back_cover_headline', $back_cover_headline,$post_id);
        update_field( 'cbf_back_cover_story', $back_cover_story,$post_id);
	    update_field( 'cbf_introduction_headline', $introduction_headline,$post_id);
        update_field( 'introduction', $introduction,$post_id);

        //update_field('recipes', $recipes, $post_id);
        insertRecipeCookBook($post_id,$recipes);

        echo json_encode(array('success'=> 'false', 'msg' => 'The Cookbook could not be inserted', 'id' => $post_id));
        wp_die();
    }

	/**
	 * Remove recipe by ID
	 */
    function deleteRecipe(){
	    $id = $_POST['id'];

	    $deleted = wp_delete_post($id);

	    if($deleted){
		    echo json_encode(array('success'=> 'true', 'msg' => 'The Recipe was deleted'));
		    wp_die();
	    }

	    echo json_encode(array('success'=> 'false', 'msg' => 'The Recipe was not deleted'));
	    wp_die();

    }

    /**
     * Get a recipe by ID
     */
    public function getRecipe(){
        $id = $_POST['id'];

        $cookbooks_recipe = getCookbooksFromRecipeId($id);
        $cookbooks_ids = [];

        foreach ($cookbooks_recipe as $cookbook_recipe){
            $cookbooks_ids[] = $cookbook_recipe['ID'];
        }

        $recipe = get_post($id);

        $images = get_field( 'cbf_photos',$id );
        $photos = [];
        foreach ($images as $image){
            $photos[] = [
                "id" => $image['image']['id'],
                "url" => $image['image']['url'],
                "caption" => $image['caption'],
               /* "primary" => $image['primary'],*/
            ];
        }

	    $recipe->photos = $photos;

	    $images = get_field( 'cbf_story_photos',$id );
	    $story_photos = [];
	    foreach ($images as $image){
		    $story_photos[] = [
			    "id" => $image['image']['id'],
			    "url" => $image['image']['url'],
			    "caption" => $image['caption'],
			   /* "primary" => $image['primary'],*/
		    ];
	    }

	    $recipe->story_photos = $story_photos;

        /*$ingredients_wo_key = get_field( 'cbf_ingredients',$id );
        $ingredients = [];
        $cont = 1;
        foreach ($ingredients_wo_key as $ingredient){
            $ingredients[] = [
                'key' => $cont++,
                'name' => $ingredient['name'],
                'quantity' => $ingredient['quantity'],
                'unit' => $ingredient['unit']['value'],
            ];
        }*/

	    //$recipe->post_content = str_replace("\r\n", '<br>', $recipe->post_content);

        $recipe->story = get_field('story', $id);
        $recipe->story_transformed = str_replace("\r\n", '<br>',get_field('story', $id));

        $recipe->headline_story = get_field('cbf_headline_story', $id);
        $recipe->headline_story_transformed = str_replace("\r\n", '<br>',get_field('cbf_headline_story', $id));

        $recipe->country = get_field('country_recipe', $id) ? get_field('country_recipe', $id)['value'] : -1;
        $recipe->country_name = get_field('country_recipe', $id) ? get_field('country_recipe', $id)['label'] : '';

        //$recipe->ingredients = $ingredients;
        $recipe->ingredients_transformed = str_replace("\r\n", '<br>', get_field('cbf_ingredients_text', $id));
        $recipe->ingredients = get_field('cbf_ingredients_text', $id);

	    $recipe->instructions_transformed = str_replace("\r\n", '<br>', get_field('cbf_instructions', $id));
	    $recipe->instructions = get_field('cbf_instructions', $id);

        $recipe->post_status  = ucfirst($recipe->post_status);

        $term_obj_list = get_the_terms( $id, 'cat_recipe' );

        $recipe->category = $term_obj_list ? $term_obj_list[0]->term_id : -1;
        $recipe->category_name = $term_obj_list  ? str_replace('&amp;', '&', $term_obj_list[0]->name) : '';
        $recipe->cookbooks_ids = $cookbooks_ids;
        $recipe->cookbooks_selected = getCookbooksFromRecipeId($id);
        $recipe->url = get_permalink($id);

        if($recipe){
            echo json_encode(array('success'=> 'true', 'recipe' => $recipe));
            wp_die();
        }

        echo json_encode(array('success'=> 'false', 'msg' => 'The Recipe could not be fetched'));
        wp_die();

    }

    /**
     * Get a cookbook by ID
     */
    public function getCookbookById(){
        $id = $_POST['id'];

        if(empty($id)){
            echo json_encode(array('success'=> 'false', 'msg' => 'We could not get the cookbook without a valid id.'));
            wp_die();
        }

        $cookbook = get_post($id);

        $cookbook->front_image = get_field( 'cbf_front_cover_image',$id ) ? get_field( 'cbf_front_cover_image',$id ) : -1;
        $cookbook->introduction_image = get_field( 'cbf_introduction_image',$id ) ? get_field( 'cbf_introduction_image',$id ) : -1;
        $cookbook->back_image = get_field( 'cbf_back_cover_image',$id ) ? get_field( 'cbf_back_cover_image',$id ) : -1;
        $cookbook->author =  get_field( 'cbf_author_name',$id );
        $cookbook->dedication =  get_field( 'dedication',$id );
        $cookbook->dedication_transformed =  str_replace("\r\n", '<br>', get_field('dedication', $id));
        $cookbook->back_cover_story =  get_field( 'cbf_back_cover_story',$id );
        $cookbook->back_cover_story_transformed =  str_replace("\r\n", '<br>', get_field('cbf_back_cover_story', $id));
        $cookbook->introduction = get_field( 'introduction',$id );
        $cookbook->introduction_transformed = str_replace("\r\n", '<br>', get_field('introduction', $id));
        $cookbook->introduction_headline = get_field( 'cbf_introduction_headline',$id );
        $cookbook->introduction_headline_transformed = str_replace("\r\n", '<br>', get_field('cbf_introduction_headline', $id));
        $cookbook->back_cover_headline = get_field( 'cbf_back_cover_headline',$id );
        $cookbook->back_cover_headline_transformed = str_replace("\r\n", '<br>', get_field('cbf_back_cover_headline', $id));
        $cookbook->recipes = get_field( 'recipes',$id );
        $cookbook->selected_recipes = getRecipesFromCookbookId($id);
        $cookbook->state = get_field('state', $id);

	    $cookbook->preview_pdf = null;

        if($order_id = get_post_meta($id,'cookbook_order_id', true)){
	        $cookbook->preview_pdf = get_field('preview_pdf', $order_id);
        }


        echo json_encode(array('success'=> 'true', 'cookbook' => $cookbook));
        wp_die();

    }

    /**
     * Add Photo
     */
    public function AddPhoto(){
        $photo_id = cbf_upload_file($_FILES['image']);

        $image = array(
            'url' => wp_get_attachment_url( $photo_id ),
            'id' => $photo_id
        );

        if($photo_id > 0){
            echo json_encode(array('success'=> true, 'msg' => 'Photo inserted successfully', 'image' => $image));
            wp_die();
        }else{
            echo json_encode(array('success'=> false, 'msg' => 'The Photo could not be inserted'));
            wp_die();
        }
    }

    /**
     * Get the user recipes
     */
    public function getYourRecipes(){

        $author_id = $_POST['author_id'];
        $recipes = [];

        if(intval($author_id) > 0){
            $recipes = get_posts(array(
                'post_type' => 'recipe',
                'numberposts' => -1,
                'author' => intval($author_id),
                'post_status' => array('publish', 'draft','private')

            ));

            $real_recipes = [];

            foreach ($recipes as $recipe){
                //  if(intval($recipe->post_author) !== intval($author_id)) continue;
                $photos = get_field('cbf_photos', $recipe->ID);
                if(count($photos) > 0){
                    $recipe->photo_url = $photos[0]['image']['url'];
                }else{
                    $recipe->photo_url = '/wp-content/uploads/2021/11/default.jpg';
                }
            }
        }

        echo json_encode(array('success'=> true, 'recipes' => $recipes));
        wp_die();
    }

    /**
     * Add collaborator (sent invitation link by email)
     */
    function addCollaborator(){
        $first = $_POST['first'];
        $last = $_POST['last'];
        $email = $_POST['email'];
        $author_id = $_POST['author_id'];
        $password = cbf_generate_string(12);
        $token = cbf_generate_string(22);

        $collaborator = null;
        $has_account = 0;

        if(email_exists($email)){

        	$has_account = 1;

        	$collaborator = get_user_by( 'email', $email );

        	if(existCollaboratorOwner($author_id, $collaborator->ID)){
		        echo json_encode(array('success'=> false, 'msg' => 'You already added this collaborator'));
		        wp_die();
        	}

        }

        $user_id = $collaborator->ID > 0 ? $collaborator->ID : wp_create_user( $email, $password, $email );
        $user = $collaborator->ID > 0 ? $collaborator : get_user_by( 'id', $user_id );

        if($collaborator === null){
	        update_user_meta($user_id,'first_name',$first);
	        update_user_meta($user_id,'last_name',$last);
        }

        insertCollaboratorUser($author_id, $user_id, $token);

        $user->add_role( 'cbf_collaborator' );
        //$user->remove_role( 'subscriber' );

        $link = get_option('siteurl') . '/collaborator-sign-up?token=' . $token . '&email='.$email . '&first=' . $first . '&has_account=' . $has_account . '&last=' . $last . '&collaborator_id=' . $user_id . '&owner_id=' . $author_id;
        

        sendCollaboratorInvitation($email,array('first' => $first, 'link'=> $link));

        $collaborator = array('first' => $first, 'last' => $last, 'email' => $email, 'token' => $token, 'ID' => $user_id, 'status' => 'Sent');

        echo json_encode(array('success'=> true , 'collaborator' => $collaborator ));
        wp_die();

    }

    /**
     * Get all the collaborators by owner id
     */
    public function getCollaborators(){

        $user_id = $_POST['user_id'];

        $collaborators = getCollaboratorsByOwnerId($user_id);

        echo json_encode(array('success'=> true , 'collaborators' => $collaborators));
        wp_die();
    }

	/**
	 * Share Recipe by email
	 */
	public function shareRecipe(){
		$id = $_POST['id'];
		$email = $_POST['email'];
		$name = $_POST['name'];
		$message = $_POST['message'];
		$sender_name = $_POST['sender_name'];
		$recipe_title = get_the_title($id);

		$postcard_image = CBF_PLUGIN_URL . 'assets/images/postcard.png';

		$emailed = shareRecipeEmail($email, array('link' => get_permalink($id), 'message' => $message, 'name' => $name, 'image' => $postcard_image,'sender_name' => $sender_name,'recipe_title' => $recipe_title));

		if($emailed){
			echo json_encode(array('success'=> true , 'msg' => 'Postcard Shared!', 'image' => $postcard_image));
			wp_die();
		}

		echo json_encode(array('success'=> false , 'msg' => 'The postcard was not shared'));
		wp_die();

	}

    /**
     * Remove collaboration, action can only be trigger by the owner account
     */
    public function removeCollaborator(){
        $id = $_POST['collaborator_id'];
        $owner_id = $_POST['owner_id'];

        if(empty($id)){
            echo json_encode(array('success'=> false , 'msg' => 'There was am error removing the collaborator, collaborator id was not provided'));
            wp_die();
        }

		//todo check if the collaborator does not have a main account in use and remove it
        /*if(!wp_delete_user( $id )){
            echo json_encode(array('success'=> false , 'msg' => 'There user could not be deleted'));
            wp_die();
        }*/

	    global $wpdb;

	    $wpdb->query("DELETE FROM $wpdb->prefix" . "cbf_users_collaborators WHERE user_id='$owner_id' AND collaborator_id='$id'");

        echo json_encode(array('success'=> true , 'msg' => 'Collaborator deleted'));
        wp_die();

    }

    /**
     * Publish a cookbook (a woo order will be created on the process)
     */
    public function publishCookbook(){

        global $woocommerce;

        if(!isset($_POST['option'])){
            echo json_encode(array('success'=> false , 'msg' => 'We cannot publish a cookbook without an option'));
            wp_die();
        }

        $option = $_POST['option'];
        $cookbook_id = $_POST['cookbook_id'];

        /**
         * Empty cart to avoid multiple items on cart
         */
        $woocommerce->cart->empty_cart();

        $parameters = '?cookbook_id=' . $cookbook_id . '&option=' . $option;

        if(intval($option)  == CBF_TEMPLATE_OPTION){
            $template = $_POST['template'];
            $woocommerce->cart->add_to_cart(get_field('template_product','option'),1);
            $parameters .= '&template=' . intval($template);

        }else{
            $services = json_decode(str_replace("\\","",$_POST['services']));
            foreach ($services as $service) {
                $woocommerce->cart->add_to_cart(intval($service),1);
            }

        }

        echo json_encode(array('success'=> true , 'checkout_url' => site_url('/checkout' . $parameters)));
        wp_die();

    }

    /**
     * Get the countries ACF instances
     */
    function getCountries(){

	    $countries = getCountriesACF();

	    echo json_encode(array('success'=> true , 'countries' => $countries));
	    wp_die();

    }

	function getUnits(){

		$units = getUnitACF();

		echo json_encode(array('success'=> true , 'units' => $units));
		wp_die();

	}
}