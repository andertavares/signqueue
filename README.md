# Signqueue

O Signqueue é uma fila simples para compartilhar um documento que precisa ser assinado por várias pessoas, uma de cada vez. Ele foi pensado para cenários em que existe **um único administrador** responsável por enviar o documento original e acompanhar o processo.

## Para usar 
Esta é a versão curta. Para a descrição completa, vá em #Configuração.
- IMPORTANTE: modifique a senha padrão no cabeçalho de ambos os documentos 
- Faça upload dos dois arquivos em um diretório de um servidor PHP com SQLite habilitado. Digamos que você tenha o site fulano.com e use o diretório 'assinar'. A URL de envio de documentos será fulano.com/assinar e a administrativa será fulano.com/admin.php.

## Como funciona

1. O administrador acessa a URL de envio de documentos (e.g. fulano.com/assinar), coloca a senha e envia o documento original.
2. O sistema gera um link para compartilhar com as pessoas que devem assinar.
3. Cada pessoa acessa o link, baixa o documento e assina usando a plataforma de
   sua preferência (por exemplo, o gov.br). Se alguém tentar assinar enquanto o documento está com outra pessoa, o sistema bloqueia.
4. Depois, quem pegou, devolve o documento assinado pelo mesmo link. Caso a pessoa pegue o documento num navegador e vá devolver em outro, deve usar o PIN  gerado quando obteve o documento.
5. A devolução libera o documento para que a próxima pessoa possa
   assiná-lo.

O sistema mantém o controle de quem está com o documento e registra as versões
devolvidas, evitando que duas pessoas assinem simultaneamente. O documento pode ser desbloqueado à força e as versões podem ser acompanhadas pelo painel administrativo (admin.php).

## Configuração

O gerenciador é deliberadamente muito simples: há apenas um administrador e a
senha é configurada diretamente no código. **Antes de colocar o sistema em
uso, altere a senha padrão no início dos dois arquivos abaixo:**

- `index.php`: altere o valor de `$senha_admin`;
- `admin.php`: altere o valor de `$senha_criacao`.

A senha padrão (`mudar123`) existe apenas como exemplo e DEVE SER ALTERADA!

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
