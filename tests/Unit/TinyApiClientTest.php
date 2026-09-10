<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Fiscal\TinyApiClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TinyApiClientTest extends TestCase
{
    public function testMapsTinyFiscalEndpointsAndKeepsTokenInFormBody(): void
    {
        $calls=[];
        $transport=static function(string $url,array $params) use (&$calls):array {
            $calls[]=['url'=>$url,'params'=>$params];
            if(str_ends_with($url,'info.php'))return['retorno'=>['status'=>'OK','conta'=>['razao_social'=>'Loja Teste','cnpj_cpf'=>'00.000.000/0001-00']]];
            if(str_ends_with($url,'pedidos.pesquisa.php'))return['retorno'=>['status'=>'Erro','codigo_erro'=>20,'erros'=>[['erro'=>'A Consulta não retornou registros']]]];
            if(str_ends_with($url,'pedido.incluir.php'))return['retorno'=>['status'=>'OK','registros'=>[['registro'=>['status'=>'OK','id'=>321,'numero'=>'1001']]]]];
            if(str_ends_with($url,'pedido.alterar.situacao'))return['retorno'=>['status'=>'OK']];
            if(str_ends_with($url,'gerar.nota.fiscal.pedido.php'))return['retorno'=>['status'=>'OK','registros'=>[['registro'=>['idNotaFiscal'=>654,'numero'=>77,'serie'=>1]]]]];
            if(str_ends_with($url,'nota.fiscal.emitir.php'))return['retorno'=>['status'=>'OK','nota_fiscal'=>['id'=>654,'situacao'=>6,'descricao_situacao'=>'Autorizada','chave_acesso'=>str_repeat('1',44),'xml'=>'<nfeProc/>']]];
            if(str_ends_with($url,'nota.fiscal.obter.link.php'))return['retorno'=>['status'=>'OK','link_nfe'=>'https://tiny.com.br/doc.view.php?id=abc']];
            throw new RuntimeException('Endpoint inesperado: '.$url);
        };

        $client=new TinyApiClient('token-da-loja',$transport);
        self::assertSame('Loja Teste',$client->account()['razao_social']);
        self::assertNull($client->findOrderByEcommerce('SO-1'));
        $created=$client->createOrder(['numero_pedido_ecommerce'=>'SO-1']);
        self::assertSame(321,$created['id']);
        $client->approveOrder(321);
        self::assertSame(654,$client->generateInvoice(321)['idNotaFiscal']);
        self::assertSame(6,$client->emitInvoice(654)['situacao']);
        self::assertSame('https://tiny.com.br/doc.view.php?id=abc',$client->getInvoiceLink(654));

        self::assertNotEmpty($calls);
        foreach($calls as $call){
            self::assertStringStartsWith('https://api.tiny.com.br/api2/',$call['url']);
            self::assertSame('token-da-loja',$call['params']['token']);
            self::assertSame('JSON',$call['params']['formato']);
        }
    }

    public function testTinyBusinessErrorIsNotSilencedOutsideSearch(): void
    {
        $client=new TinyApiClient('invalid',static fn(string $url,array $params):array=>['retorno'=>['status'=>'Erro','codigo_erro'=>2,'erros'=>[['erro'=>'token invalido']]]]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tiny [2]: token invalido');
        $client->account();
    }
}
