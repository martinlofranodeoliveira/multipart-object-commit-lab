# API — desenvolvimento 0.1.0

O [contrato normativo](contract.md) define o subconjunto implementado. Esta tradução operacional não amplia seu escopo.

## Operações

- `createBatch(files)`: `POST /batches`, resposta 201 com lote completo.
- `getBatch(batchId)`: `GET /batches/{id}`, resposta 200 com snapshot das referências ordenadas.
- `readObject(digest)`: `GET /objects/{digest}`, resposta 200 com os bytes exatos como anexo `application/octet-stream`.

IDs têm 32 caracteres hexadecimais minúsculos; digests têm 64. Não há listagem, exclusão, HEAD, ranges ou parâmetros de consulta. Rotas não reconhecidas não chegam ao PHP. nginx encaminha somente para um script fixo, nunca para nomes fornecidos no upload.

## Multipart canônico

Envie um campo textual `labels` com lista JSON e uma lista plana `files[]`, usando PHP/curl ou o controlador fornecido. Os rótulos e arquivos devem corresponder por posição. A requisição precisa de comprimento conhecido e corpo de até 9 MiB, incluindo o overhead multipart.

Exemplo executável usando o laboratório, que efetivamente chama curl e o parser multipart do FPM:

```php
require 'src/bootstrap.php';
$s = new CommitLab\Server();
try {
    $r = $s->createBatch(['abc', 'xyz'], ['primeiro', '../run/escaped.php']);
    if ($r['status'] !== 201) {
        throw new RuntimeException($r['body']);
    }
    $lote = json_decode($r['body'], true, 16, JSON_THROW_ON_ERROR);
    echo $s->getBatch($lote['id'])['body'];
} finally {
    $s->close();
}
```

São aceitos 1–4 arquivos, inclusive vazios, de no máximo 2.097.152 bytes cada. Todos os arquivos precisam de `UPLOAD_ERR_OK`, tamanho real correto e origem reconhecida por `is_uploaded_file`. Todos os hashes são calculados antes da publicação de qualquer associação.

Cada rótulo contém 1–256 bytes UTF-8, sem NUL, e é único por comparação de bytes no lote. Não há normalização Unicode nem remoção de espaços. `name`, `full_path` e tipo MIME informados pelo cliente não determinam caminhos, execução ou tipo de resposta. Rótulos são dados não confiáveis e devem ser escapados pela interface consumidora.

O PHP faz a normalização dos campos; a aplicação não reconstitui partes brutas nem promete rejeitar toda sintaxe multipart ambígua. nginx limita o corpo antes do FastCGI, e a admissão aplica os limites do parser antes de publicar metadados; isso não implica limite rígido de RSS do parser. O parser explícito usa limites de 4 arquivos / 5 partes / 1 campo e lança exceção ao excedê-los. A leitura automática POST fica desativada, evitando aceitar um lote truncado.

## Respostas e erros

Um lote retorna `id` e `files`; cada entrada contém `label`, `digest` e `size`. Objetos órfãos instalados sem referências confirmadas não são legíveis pela API, e `readObject(digest)` valida tipo de arquivo regular, tamanho exato e hash SHA-256 antes de transmitir os bytes. Um erro da aplicação contém `error.code` e `error.message`, por exemplo:

```json
{"error":{"code":"invalid_labels","message":"Labels must be bytewise unique within a batch."}}
```

Use código e status, não o texto humano, como interface estável. 422 indica validação, 409 capacidade, 413 corpo grande, 415 tipo incorreto, 404 ausência, 405 método incorreto e 503 indisponibilidade/trava/integridade. nginx/FPM podem retornar erros de transporte não JSON.

A publicação do lote é indivisível no SQLite. Deduplicação não une IDs de lote nem elimina referências distintas. SHA-256 identifica conteúdo, não autentica a origem. Perder a resposta após COMMIT não desfaz o lote; repetir o upload pode criar outro lote. Não existe chave de idempotência nem promessa de execução exatamente uma vez.

Limites globais, prazos, accounting de bytes e exclusões estão no [contrato](contract.md). A [recuperação](recovery.pt-BR.md) usa a mesma trava dos escritores e não remove objetos referenciados.
