# Recuperação — desenvolvimento 0.1.0

A recuperação é explícita e local. Não há relógio de expiração. Um objeto está vivo quando existe uma referência confirmada em `refs` para seu digest.

## Uso

```sh
php bin/recover.php /tmp/mocl-private-root --json
```

O caminho ilustra uma raiz privada canônica; use a raiz real pertencente ao seu controlador local. O comando valida marcador, UID, permissões e travas, sem criar nem adotar diretórios externos. Exemplo de resultado para um órfão de três bytes:

```json
{"staged_removed":0,"objects_removed":1,"bytes_removed":3}
```

Sem `--json`, uma frase legível precede a linha JSON. Códigos de saída: 0 sucesso, 1 falha, 2 uso incorreto. Os bytes representam comprimentos lógicos observados, não blocos físicos recuperados. Uma falha de filesystem pode interromper uma coleta parcialmente executada; repetir a operação é seguro dentro do modelo cooperativo, mas uma execução interrompida não fornece relatório de sucesso.

O laboratório verifica o próprio comando por meio de `Server::recover()`. `php bin/demo.php --json` provoca mortes reais de workers antes e depois do commit, usando dados sintéticos. A demonstração remove seu namespace após encerrar os processos; não mantém um serviço persistente.

## Disciplina de segurança

A recuperação toma `writer.lock` exclusivamente, a mesma trava que cobre staging, instalação e commit. A espera é de no máximo dois segundos. Enquanto mantém a trava, lê referências confirmadas e planeja exclusões apenas nos diretórios fixos `staging/` e `objects/`, com no máximo 1.024 entradas por diretório.

Só remove arquivos regulares do UID, com um único hardlink, cujo nome seja `{32 hex minúsculos}.stage`, ou `{64 hex minúsculos}` sem referência confirmada. Planeja os dois inventários antes da primeira exclusão. Ao final solicita checkpoint truncante do WAL.

Não remove nem segue symlinks, diretórios, nomes desconhecidos ou objetos referenciados. Não coleta temporários do parser, nginx, logs, banco ou arquivos de entrada do cliente. A limpeza dos temporários do parser pertence ao PHP e ao ciclo de vida dos processos; não se presume inatividade com base no nome ou horário.

## Interrupções

- Durante staging: pode sobrar um stage parcial reconhecível, sem metadados publicados.
- Depois da instalação e antes do commit: podem sobrar objetos completos invisíveis à leitura pública; serão coletados se não referenciados.
- Durante a transação: o SQLite determina commit ou rollback do lote inteiro, nunca parte das referências.
- Depois do commit e antes da resposta: todas as referências permanecem, e a coleta preserva seus objetos. Repetir o pedido após perder a resposta pode criar outro lote com os mesmos objetos.

Os testes usam barreiras fixas e marcadores privados de PID/início do processo. O controlador grava uma solicitação privada na barreira; o próprio worker a consome e envia SIGKILL a si mesmo. O master FPM é responsável pelo reap do worker. O controlador nunca sinaliza um PID numérico de worker. O encontro dos uploads concorrentes ocorre em `after_hash`, antes da trava. Esperar um segundo escritor em `after_install` enquanto o primeiro mantém a trava causaria deadlock e não faz parte deste protocolo.

## Limitações

Não recria bytes referenciados ausentes, não repara corrupção, não autentica origem e não exclui lotes confirmados. Entradas desconhecidas ficam para inspeção. Leituras de objetos referenciados ausentes, com symlink, tamanho inconsistente ou digest corrompido falham sem expor outro caminho nem bytes corrompidos. Processos hostis com o mesmo UID e corrupção do filesystem estão fora do modelo de trava consultiva.

Preserve o namespace durante investigação. Não use a recuperação em diretórios de outra aplicação. A remoção integral do namespace é exclusiva do controlador que o criou, após encerrar e coletar seus processos. Renomear objetos e confirmar SQLite não constitui uma transação distribuída nem uma garantia universal contra queda de energia.
