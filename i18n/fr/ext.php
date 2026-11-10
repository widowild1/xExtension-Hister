<?php

return [
    'hister' => [
        'api_url' => 'URL de l\'instance Hister',
        'api_url_help' => 'Exemple : http://127.0.0.1:4433. Sous Docker, utilisez l\'adresse de l\'hôte, pas localhost.',
        'endpoint_path' => 'Chemin du endpoint API',
        'endpoint_path_help' => 'Par défaut : /api/add (voir la documentation de votre instance sur /api-docs).',
        'api_token' => 'Clé d\'API (Bearer Token)',
        'default_tags' => 'Tags par défaut (séparés par une virgule)',
        'default_tags_help' => 'Réunis dans le champ « label » de Hister, séparés par des virgules.',
        'include_category_tag' => 'Ajouter la catégorie FreshRSS comme tag',
        'include_feed_tag' => 'Ajouter le nom du flux comme tag',
        'auto_sync_all' => 'Synchroniser automatiquement tout nouvel article reçu',
        'verify_ssl' => 'Vérifier le certificat SSL (à décocher uniquement pour tests locaux)',
        'send_button_title' => 'Envoyer cet article à Hister',
        'success_notification' => 'Article transmis avec succès à Hister.',
        'error_notification' => 'Erreur lors de la transmission à Hister (consultez les journaux FreshRSS).',
        'api_token_help' => 'Facultatif : à renseigner seulement si votre instance exige un jeton (app.access_token ou mode multi-utilisateurs).',
        'include_entry_tags' => 'Ajouter aussi les tags de l\'article (catégories du flux et labels FreshRSS si disponibles)',
        'ignore_skip_rules' => 'Ignorer les règles d\'exclusion de Hister (skip/allow) pour ces envois',
        'sync_starred' => 'Synchroniser les articles mis en favoris',
        'sync_labeled' => 'Synchroniser les articles labellisés (les labels FreshRSS deviennent des tags Hister)',
        'sync_help' => 'Exécuté après chaque actualisation des flux. Un article est renvoyé si ses labels changent.',
        'sync_now' => 'Enregistrer et synchroniser maintenant',
    ],
];
