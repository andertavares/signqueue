# Signqueue

O Signqueue é uma fila simples para compartilhar um documento que precisa ser
assinado por várias pessoas, uma de cada vez. Ele foi pensado para cenários em
que existe **um único administrador** responsável por controlar o documento e
acompanhar o processo.

## Como funciona

1. O administrador acessa a área administrativa e envia o documento.
2. O sistema gera um link para compartilhar com as pessoas que devem assinar.
3. A pessoa acessa o link, baixa o documento e assina usando a plataforma de
   sua preferência (por exemplo, o gov.br).
4. Depois, ela devolve o documento assinado pelo mesmo link.
5. A devolução libera o documento para que a próxima pessoa da fila possa
   assiná-lo.

O sistema mantém o controle de quem está com o documento e registra as versões
devolvidas, evitando que duas pessoas assinem simultaneamente.

## Configuração

O gerenciador é deliberadamente muito simples: há apenas um administrador e a
senha é configurada diretamente no código. **Antes de colocar o sistema em
uso, altere a senha padrão no início dos dois arquivos abaixo:**

- `index.php`: altere o valor de `$senha_admin`;
- `admin.php`: altere o valor de `$senha_criacao`.

Use a mesma senha nos dois arquivos. A senha padrão (`mudar123`) existe apenas
como exemplo e não deve ser mantida em uma instalação real.

O sistema precisa de um servidor com PHP, SQLite habilitado e permissão para
criar e gravar o arquivo `database.sqlite` no diretório da aplicação. O
administrador deve usar `admin.php`; o link público para a fila é exibido pelo
próprio sistema após o envio do documento.

## Aviso importante

**ESTE SISTEMA É FORNECIDO SEM QUALQUER GARANTIA.** Não há garantia de
disponibilidade, segurança, integridade, armazenamento ou entrega dos arquivos.
Cada pessoa utiliza o Signqueue **por sua própria conta e risco** e deve manter
cópias de segurança dos documentos, verificar os arquivos recebidos e avaliar
se a aplicação atende às suas necessidades antes de usá-la com informações
importantes ou sensíveis.

## Licença

Este projeto é distribuído sob a licença MIT. Consulte o arquivo `LICENSE` para
obter o texto completo.
