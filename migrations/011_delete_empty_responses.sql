-- Remove response records that have zero linked answers (empty submissions).
DELETE r
FROM responses r
LEFT JOIN answers a ON a.response_id = r.id
WHERE a.id IS NULL;
