# Multipart Object Commit Lab

Versão de desenvolvimento **0.1.0**, da **Lofrano Tecnologia**. Software PHP original sob licença MIT para estudar admissão multipart, deduplicação por conteúdo, visibilidade atômica de lotes e recuperação entre a instalação de objetos e o commit SQLite. É um laboratório local limitado, não uma recomendação de implantação nem uma declaração de prontidão para produção.

[English](README.md) · [Contrato](docs/contract.md) · [API em português](docs/api.pt-BR.md) · [Recuperação](docs/recovery.pt-BR.md) · [Projeto](docs/design.md)

## Instalação e qualificação

Requer Linux, PHP CLI/FPM 8.4+ com PDO SQLite, POSIX e hash, nginx, curl e `setsid`. Não usa Composer. O controlador recusa UID 0. Processos, workers, socket FastCGI, configurações, logs e temporários ficam no namespace privado do UID do controlador; nenhum serviço do sistema é iniciado.

A biblioteca SQLite realmente vinculada deve ser **3.54.1**, acima do mínimo 3.51.3. A base PHP e `dependency-metadata/sqlite-source.json` registram URL oficial, checksums do arquivo e da amalgamação, e source ID esperado. A imagem verifica os pins, compila a biblioteca compartilhada e qualifica o SQL/FTS5 real do PDO. Não exige variáveis do repositório nem segredos. Veja [runtime.md](docs/runtime.md).

```sh
docker build -t multipart-object-commit-lab:local .
docker run --rm --network none multipart-object-commit-lab:local php bin/qualify.php
docker run --rm --network none --read-only --tmpfs /tmp:rw,nosuid,nodev,size=192m multipart-object-commit-lab:local php tests/run.php
docker run --rm --network none --read-only --tmpfs /tmp:rw,nosuid,nodev,size=192m multipart-object-commit-lab:local php bin/demo.php
```

A preparação de dependências ocorre somente na construção da imagem. São registrados os pacotes instalados e o source ID efetivo do SQLite. Não há Docker aninhado, contêiner privilegiado, credenciais nem API externa em execução. Em um host já qualificado, como usuário sem privilégios e a partir de um caminho de instalação ASCII:

```sh
php bin/qualify.php
php tests/run.php
php bin/demo.php
php bin/demo.php --json
```

Testes e demonstração iniciam seus próprios nginx e FPM. Cada cenário tem prazo monotônico de dez segundos. O namespace é removido somente após encerramento dos grupos de processos próprios e coleta dos processos mestres/clientes.

## Demonstração com verificações

Sete cenários determinísticos usam somente bytes sintéticos. Verificam dois arquivos no mesmo lote, deduplicação entre lotes, quarto arquivo grande demais, cinco arquivos pequenos, morte do worker antes e depois do commit, concorrência de uploads idênticos e rótulos com aparência de travessia de diretórios. Oráculos independentes usam bytes via curl, inventário de diretórios, vetores SHA-256 conhecidos e consultas SQL diretas. IDs, PIDs e caminhos aleatórios não aparecem no relatório.

Exemplo de saída humana:

```text
Multipart Object Commit Lab — development 0.1.0
PASS two-file publication and independent byte/SHA/SQL oracles
PASS deduplication preserves separate batch references
PASS oversize final file and five-file request publish nothing
PASS precommit crash leaves only an invisible recoverable orphan
PASS postcommit lost acknowledgement retains one complete batch
PASS concurrent identical uploads rendezvous without lock deadlock
PASS traversal-style labels remain data and caller paths survive
7 asserting scenarios passed; synthetic bytes, loopback HTTP, no remote services.
```

A última linha adicional é JSON com `version`, `ok`, `scenarios` e os vetores `abc`/`xyz`. `--json` emite somente essa linha; o exemplo completo está no README em inglês. São exemplos do contrato de saída, não alegações de execução em um ambiente específico ou medições de desempenho.

## Exemplo pequeno

```php
require 'src/bootstrap.php';

$servidor = new CommitLab\Server();
try {
    $resposta = $servidor->createBatch(['abc', 'xyz'], ['rótulo A', '../não-é-caminho.php']);
    $lote = CommitLab\Scenario::json($resposta, 201);
    echo $servidor->getBatch($lote['id'])['body'];
    $objeto = $servidor->readObject($lote['files'][0]['digest']);
    assert($objeto['body'] === 'abc');
} finally {
    $servidor->close();
}
```

Rotas e métodos: `POST /batches` (`createBatch(files)`), `GET /batches/{id}` (`getBatch(batchId)`) e `GET /objects/{digest}` (`readObject(digest)`). O multipart contém um campo `labels` com lista JSON e partes `files[]`. São 1–4 arquivos de até 2 MiB cada; rótulos UTF-8 únicos são metadados, nunca nomes executáveis ou caminhos. Consulte o [contrato preciso](docs/contract.md).

## Motivações e limites

Todos os hashes e validações terminam antes da publicação. Um `flock` exclusivo cooperativo cobre reserva, staging, instalação e transação SQLite. Os objetos completos são instalados primeiro; uma única transação publica todas as associações. Leitores veem snapshots confirmados e leituras de objetos verificam metadados de arquivo regular, tamanho exato e digest SHA-256 antes de retornar o stream. A recuperação usa a mesma trava. O encontro dos dois uploads concorrentes ocorre antes da trava, evitando esperar pelo próximo escritor dentro de uma região serializada.

Limites: 4 clientes ativos/workers, 128 lotes, 512 referências, 128 objetos incluindo órfãos, 4 stages, 64 MiB lógicos observados de objetos/stages, 2.048 páginas SQLite e limiar observado de WAL de 4 MiB. São critérios de admissão, não quotas físicas. Temporários do parser e nginx são contabilizados separadamente pelo ambiente, não como payload da aplicação.

nginx limita o corpo a 9 MiB antes do FastCGI; a admissão chama o parser explícito mantido pelo PHP com o mesmo limite, antes dos hashes e da publicação de metadados. Validações posteriores e `memory_limit` **não** garantem um teto rígido de RSS antes do parser. O parser explícito do PHP 8.4 lança exceção ao exceder 4 arquivos / 5 partes / 1 campo; a leitura automática POST fica desativada, evitando truncamento silencioso.

Não há autenticação, antivírus, renderização, expiração, exclusão de lotes, armazenamento remoto ou chave de idempotência. SHA-256 identifica conteúdo, não sua origem. Repetir um pedido após perder a resposta pode criar outro lote. Travas são consultivas; processos hostis do mesmo UID, corrupção e I/O de kernel impossível de interromper ficam fora do modelo. Não há promessa de transação entre sistemas de arquivos ou durabilidade universal contra queda de energia.

Contato geral: [site oficial](https://lofranotecnologia.com.br). Segurança: [relato privado de vulnerabilidade no GitHub](https://github.com/martinlofranodeoliveira/multipart-object-commit-lab/security/advisories/new).
