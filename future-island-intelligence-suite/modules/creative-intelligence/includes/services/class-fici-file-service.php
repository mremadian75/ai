<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FICI_File_Service {
    private FICI_Settings $settings;

    public function __construct( FICI_Settings $settings ) {
        $this->settings = $settings;
    }

    public function handle_upload( string $field_name ) {
        if ( empty( $_FILES[ $field_name ] ) || ! is_array( $_FILES[ $field_name ] ) ) {
            return null;
        }

        $settings = $this->settings->get_settings();
        $file     = $_FILES[ $field_name ];

        // v0.9.27.0 — Phase 7: Server-side preflight hardening.
        // Separate image / video caps. Honor admin-configured higher caps (the
        // previous min(50, …) silently capped admin overrides at 50 MB).
        // Every validation failure now carries no_full_charge=true so the credit
        // ledger does not charge a creative analysis for a rejected upload.
        $img_cap_mb_raw = (int) ( $settings['max_image_size_mb'] ?? $settings['max_file_size_mb'] ?? 12 );
        $vid_cap_mb_raw = (int) ( $settings['max_video_size_mb'] ?? $settings['max_file_size_mb'] ?? 50 );
        $img_cap_mb = max( 1, $img_cap_mb_raw );
        $vid_cap_mb = max( 1, $vid_cap_mb_raw );

        if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
            return new WP_Error( 'fici_upload_error', $this->upload_error_message( (int) $file['error'] ), array( 'status' => 400, 'no_full_charge' => true ) );
        }

        if ( empty( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new WP_Error( 'fici_upload_missing_tmp_file', __( 'Uploaded file was not received correctly.', FICI_TEXT_DOMAIN ), array( 'status' => 400, 'no_full_charge' => true ) );
        }

        if ( empty( $file['name'] ) || ! is_string( $file['name'] ) ) {
            return new WP_Error( 'fici_upload_missing_name', __( 'Uploaded file is missing a valid filename.', FICI_TEXT_DOMAIN ), array( 'status' => 400, 'no_full_charge' => true ) );
        }

        // Quick MIME peek so we can choose the right cap before measuring size.
        // wp_check_filetype_and_ext is reliable for the basic image/video split.
        $allowed_mimes = array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'webp'         => 'image/webp',
            'gif'          => 'image/gif',
            'mp4|m4v'      => 'video/mp4',
            'mov|qt'       => 'video/quicktime',
            'webm'         => 'video/webm',
        );
        $check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed_mimes );
        if ( empty( $check['type'] ) ) {
            return new WP_Error(
                'fici_invalid_file_type',
                __( 'Tipo de archivo no soportado. Sube una imagen (JPG/PNG/WebP/GIF) o un vídeo (MP4/MOV/WebM).', FICI_TEXT_DOMAIN ),
                array( 'status' => 400, 'no_full_charge' => true )
            );
        }
        $detected_mime = (string) $check['type'];
        $is_video = ( 0 === strpos( $detected_mime, 'video/' ) );
        $cap_mb   = $is_video ? $vid_cap_mb : $img_cap_mb;
        $max_size = $cap_mb * MB_IN_BYTES;

        if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_size ) {
            $actual_mb = round( (int) $file['size'] / MB_IN_BYTES, 1 );
            return new WP_Error(
                'fici_file_too_large',
                sprintf(
                    /* translators: 1: file size, 2: kind, 3: max MB */
                    __( 'Archivo de %1$s MB supera el límite actual de %2$s (%3$d MB). Comprime el archivo o sube un clip más corto. No se han cargado créditos.', FICI_TEXT_DOMAIN ),
                    $actual_mb,
                    $is_video ? __( 'vídeo', FICI_TEXT_DOMAIN ) : __( 'imagen', FICI_TEXT_DOMAIN ),
                    $cap_mb
                ),
                array( 'status' => 400, 'no_full_charge' => true )
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $upload = wp_handle_upload(
            $file,
            array(
                'test_form' => false,
                'mimes'     => $allowed_mimes,
            )
        );

        if ( isset( $upload['error'] ) ) {
            return new WP_Error( 'fici_upload_failed', sanitize_text_field( $upload['error'] ), array( 'status' => 500, 'no_full_charge' => true ) );
        }

        $asset_type = 0 === strpos( (string) $upload['type'], 'image/' ) ? 'image' : 'video';

        return array(
            'asset_type' => $asset_type,
            'file_path'  => isset( $upload['file'] ) ? (string) $upload['file'] : '',
            'file_url'   => isset( $upload['url'] ) ? esc_url_raw( (string) $upload['url'] ) : '',
            'mime_type'  => isset( $upload['type'] ) ? (string) $upload['type'] : '',
            'file_size'  => isset( $file['size'] ) ? (int) $file['size'] : 0,
        );
    }

    public function image_to_data_url( string $file_path, string $mime_type ) {
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            return new WP_Error( 'fici_file_not_readable', __( 'Uploaded image cannot be read.', FICI_TEXT_DOMAIN ), array( 'status' => 500 ) );
        }

        if ( 0 !== strpos( $mime_type, 'image/' ) ) {
            return new WP_Error( 'fici_not_image', __( 'Only images can be sent directly to the vision model in v0.1.', FICI_TEXT_DOMAIN ), array( 'status' => 400 ) );
        }

        $settings = $this->settings->get_settings();
        $max_read = max( 1, min( 50, (int) $settings['max_file_size_mb'] ) ) * MB_IN_BYTES;
        $size = filesize( $file_path );
        if ( false !== $size && (int) $size > $max_read ) {
            return new WP_Error( 'fici_file_too_large_to_read', __( 'The uploaded file is too large for safe AI analysis. No full analysis charge should be applied.', FICI_TEXT_DOMAIN ), array( 'status' => 400, 'no_full_charge' => true ) );
        }

        $contents = file_get_contents( $file_path );
        if ( false === $contents ) {
            return new WP_Error( 'fici_file_read_failed', __( 'Could not read uploaded image.', FICI_TEXT_DOMAIN ), array( 'status' => 500 ) );
        }

        return 'data:' . $mime_type . ';base64,' . base64_encode( $contents );
    }

    private function upload_error_message( int $code ): string {
        $messages = array(
            UPLOAD_ERR_INI_SIZE   => __( 'Uploaded file exceeds the server upload limit.', FICI_TEXT_DOMAIN ),
            UPLOAD_ERR_FORM_SIZE  => __( 'Uploaded file exceeds the form upload limit.', FICI_TEXT_DOMAIN ),
            UPLOAD_ERR_PARTIAL    => __( 'Uploaded file was only partially uploaded.', FICI_TEXT_DOMAIN ),
            UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded.', FICI_TEXT_DOMAIN ),
            UPLOAD_ERR_NO_TMP_DIR => __( 'Server temporary upload folder is missing.', FICI_TEXT_DOMAIN ),
            UPLOAD_ERR_CANT_WRITE => __( 'Server could not write the uploaded file.', FICI_TEXT_DOMAIN ),
            UPLOAD_ERR_EXTENSION  => __( 'A server extension stopped the upload.', FICI_TEXT_DOMAIN ),
        );
        return sanitize_text_field( $messages[ $code ] ?? __( 'Unknown upload error.', FICI_TEXT_DOMAIN ) );
    }

}
