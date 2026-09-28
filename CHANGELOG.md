# Changelog

## [Non publié]

### Ajouté
- **Coffre des secrets** : entité `Secret` (portée pour les futurs comptes, nom unique par portée), chiffrement XChaCha20-Poly1305 avec `ROCKET_SECRETS_KEY` et rotation (`ROCKET_SECRETS_KEY_PREVIOUS`, `rocket:secrets:rotate`), service `SecretVault` (`get`, `find`, `getOrEnv`, `has`, `set`, `delete`, `list`), API administrateur `/api/secrets` (valeurs jamais renvoyées), commandes `rocket:secrets:generate-key` et `rocket:secrets:import-env PREFIX`, sonde « Coffre des secrets » du tableau de bord.
- Layer : page Administration → Secrets, composants `SecretField` (choisir ou créer un secret dans un formulaire de connecteur) et `SecretForm`.

### Modifié
- Le tableau de bord liste le service « Coffre des secrets » (désactivé tant que le coffre est vide).
