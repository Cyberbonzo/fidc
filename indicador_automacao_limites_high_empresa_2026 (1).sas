%include "/sasdados/dicre/executivo/matricial/restrito/dicre/coordenadoria/uteis/config.sas";
%MAPEARLIBS("SAS", "NREP");	
%LET usuario = DB2SCRED; 
%ndb2(ANC, &usuario);
%ndb2(MCI, &usuario);
%ndb2(ACP, &usuario);




*Tabela base temporária;
data _null_;
	format data_hora datetime25.6 ;
	data_hora = datetime();
	dt = put(datepart(data_hora), yymmdd6.);
	hr = put(hour(timepart(data_hora)),z2.);
	mn = put(minute(timepart(data_hora)), z2.);
	sc = put(second(timepart(data_hora)), z2.);
	temp = 'm10'||dt||hr||mn||sc;
	call symputx('temp', temp);
	call symputx('data_hora', data_hora);
run;



proc sql;
connect to db2 (authdomain=&usuario database=bdb2p04);
 create table apvd as
 	   select * 
         from connection to db2(
		      select a.cd_cli, a.ts_aclt_slc, a.nr_seql_slct, a.nr_seql_pecs_nvl
				   , x.cd_est_lmcr, x.cd_cpf_cgc, a.vl_lim_crd_apvd, x.cd_rsco_crd_cli, a.dt_vnct_ppto, x.cd_mtdl_anl_crd
				from db2anc.prpt_lmcr_nvl as a
		  inner join (select cd_cli, max(dt_apvc_lim) as dt_apvc_lim
						from db2anc.prpt_lmcr_nvl 
					group by cd_cli)as b
				  on a.cd_cli = b.cd_cli and a.dt_apvc_lim = b.dt_apvc_lim 
		  inner join db2anc.lmcr_cli as x			
		          on a.cd_cli = x.cd_cli and a.ts_aclt_slc = x.ts_aclt_slc 
			   where a.dt_apvc_lim >= '2021-12-31'
				 and x.cd_est_lmcr in (10, 20, 30, 40, 80)  
      			 and x.cd_mtdl_anl_crd in (60, 61, 80, 88)
				 and a.dt_vnct_ppto >= '2022-12-31')
	order by cd_cli, dt_vnct_ppto;
disconnect from db2;
quit;

proc sort data=apvd out=apvd nodupkey; by cd_cli;



proc sql;
	  drop table &usuario..&temp._tabela_grp;
	create table &usuario..&temp._tabela_grp as
	 	  select cd_cli            format best32.
/*		  	   , cd_grupo          format best32.	*/
			from apvd;
quit;


proc sql;
connect to db2 (authdomain=&usuario database=bdb2p04);

   create table fatm as
         select * 
		   from connection to db2(
				select distinct a.*, b.vlr as fba, b.vlr/12 as fm
						   from &usuario..&temp._tabela_grp a
				 	  left join db2mci.faturamento b
						     on a.cd_cli = b.f_cliente_cod
					 inner join (select f_cliente_cod, max(nr_seql_fatm) as nr_seql_fatm_min 
						 		   from db2mci.faturamento 
							   group by f_cliente_cod) as c 
								 	 on b.f_cliente_cod = c.f_cliente_cod and b.nr_seql_fatm >= c.nr_seql_fatm_min)
		 where fba > 30000000 and fba <= 100000000
	  order by fba;
disconnect from db2;
quit;

proc sql;
	  drop table &usuario..&temp._tabela_grp;
	create table &usuario..&temp._tabela_grp as
	 	  select cd_cli            format best32.
/*		  	   , cd_grupo          format best32.	*/
			from fatm;
quit;


proc sql;
connect to db2 (authdomain=&usuario database=bdb2p04);

   create table nat_jur as
         select * 
		   from connection to db2(
				select distinct a.*, b.cod_natu_juri
						   from &temp._tabela_grp as a
				      left join db2mci.pessoa_juridica as b
					  		 on a.cd_cli = b.f_cliente_cod
						  where b.cod_natu_juri in (26, 28, 39, 40, 45, 46, 48, 58, 60, 61, 65))
		  order by cd_cli;
disconnect from db2;
quit;


proc sql;
	  drop table &usuario..&temp._tabela_grp;
	create table &usuario..&temp._tabela_grp as
	 	  select cd_cli            format best32.
/*		  	   , cd_grupo          format best32.	*/
			from nat_jur;
quit;

proc sql;
  connect to db2 (authdomain=&usuario database=bdb2p04);
create table setor as
      select a.cd_cli
		   , a.cod       	                 as Cod_Atvd
		   , propcase(b.ds_atividade_mci)    as Atividade
		   , b.cd_macrossetor_grc            as Cod_Macrossetor
		   , propcase(b.ds_macrossetor_grc)  as Nom_Macrossetor
 		   , b.cd_setor_mci                  as Cod_Setor 
		   , propcase(b.ds_setor_mci)        as Nom_Macro
		   , case when b.cd_segmento_mci = 2 then 'Indústria'
		   		  when b.cd_segmento_mci = 3 then 'Comércio'
				  when b.cd_segmento_mci = 4 then 'Prestação de Serviço'
				  when b.cd_segmento_mci = 5 then 'Adm. Pública'
				  else '.'
             end as Segmento
		   , a.cod_sigl_uf as Estado
		   , case when a.cod_sigl_uf in ('RS', 'SC', 'PR') then 'Sul'
			  	  when a.cod_sigl_uf in ('SP', 'RJ', 'MG', 'ES') then 'Sudeste'
				  when a.cod_sigl_uf in ('DF', 'MT', 'MS', 'GO') then 'Centro_oeste'
				  when a.cod_sigl_uf in ('AC', 'AP', 'AM', 'RR', 'RO', 'TO', 'PA') then 'Norte'
				  else 'Nordeste'
  				end as Regiao
	    from connection to db2(
		     select a.cd_cli
	       		  , b.cod
				  , b.cod_sigl_uf
		   	   from &usuario..&temp._tabela_grp as a
		  left join db2mci.atividade_econom   as b		 
				 on a.cd_cli = b.f_pessjuri_cliente and b.ind_atvd_prin = 'S') as a

   left join nrep.tabela_atvd_setor   as b
          on a.cod = b.cd_atividade_mci
	   where b.cd_setor_mci not in (2, 3, 4, 42, 43, 45, 48)

    order by cd_cli;

disconnect from db2;
quit;


proc sql;
	  drop table &usuario..&temp._tabela_grp;
	create table &usuario..&temp._tabela_grp as
	 	  select cd_cli            format best32.
/*		  	   , cd_grupo          format best32.	*/
			from setor;
quit;



proc sql;
connect to db2 (authdomain="&usuario" database=bdb2p04);

create table grupo as
      select *
	  	from connection to db2 (
			 select distinct a.cd_cli
						   , b.cd_gr_rlc_cli as cd_grupo
						   , c.cd_tip_gr_rlc as tipo_grupo
		/*				   , d.cd_cli_vcld_gr*/
					    from &temp._tabela_grp as a 
				   left join (select a.cod as cd_cli, b.cd_gr_rlc_cli, b.in_cli_cnsd_gr
				                from db2mci.cliente as a
				  	       left join db2mci.vclc_cli_gr_rlc as b
				                  on a.cod = b.cd_cli_vcld_gr
							   where a.cod_tipo = 2) as b 
						  on a.cd_cli = b.cd_cli
				   left join db2mci.gr_rlc_cli as c
				          on b.cd_gr_rlc_cli = c.cd_gr_rlc_cli
				   left join db2mci.vclc_cli_gr_rlc as d
				          on b.cd_gr_rlc_cli = d.cd_gr_rlc_cli 
					   where c.cd_tip_gr_rlc in (1) and  b.in_cli_cnsd_gr  = 'S' 
)
   order by  cd_grupo;
disconnect from db2;
quit;


proc sql;
   create table nao_integra_grp_eco  as
select distinct cd_cli
	       from &usuario..&temp._tabela_grp	
	      where cd_cli not in (select cd_cli from grupo)
/*	   order by rand('NORMAL');*/
;
quit;



proc sql;
	  drop table &usuario..&temp._tabela_grp_2;
	create table &usuario..&temp._tabela_grp_2 as
	 	  select cd_cli            format best32.
/*		  	   , cd_grupo          format best32.	*/
			from nao_integra_grp_eco;
quit;


/* condições mínimas sócios */ 

proc sql;
connect to db2 (authdomain=&usuario database=bdb2p04);
drop table &usuario..&temp._g1;
drop table &usuario..&temp._g2;
drop table &usuario..&temp._g3;
drop table &usuario..&temp._g4;

execute(create view &temp._g1 as

			  select distinct a.cd_cli, e.cod as cd_cli_relac, 10 as tip_rlc
			  			    , b.prc_cptl_totl
							, b.prc_acao_ordn
						 from &temp._tabela_grp_2 as a
 		  		    left join db2mci.participacao_cptl as b
		  			 	   on a.cd_cli = b.f_pessjuri_cliente
			       	left join db2mci.cliente as c 
						   on b.f_cliente_cod = c.cod
				   	left join db2mci.participacao_cptl as d
				   		   on c.cod = d.f_cliente_cod
				    left join db2mci.cliente as e
				   		   on d.f_cliente_cod = e.cod
		   	            where (((b.dta_fim = '0001-01-01' or b.dta_fim is null or b.dta_fim >= current_date) and (lower(b.ind_ingr) = 'S')) 
				   	       or  ((b.dta_fim = '0001-01-01' or b.dta_fim is null or b.dta_fim >= current_date))
						   or  ((b.dta_fim = '0001-01-01' or b.dta_fim is null or b.dta_fim >= current_date)))
						  and c.cod_tipo = 2
			  union
			  select distinct a.cd_cli, e.cod as cd_cli_relac, 11 as tip_rlc
                            , b.prc_cptl_totl
							, b.prc_acao_ordn    
						 from &temp._tabela_grp_2 as a
 		  		    left join db2mci.participacao_cptl as b
		  			 	   on a.cd_cli = b.f_pessjuri_cliente
			       	left join db2mci.cliente as c 
						   on b.f_cliente_cod = c.cod
				   	left join db2mci.participacao_cptl as d
				   		   on c.cod = d.f_cliente_cod
				    left join db2mci.cliente as e
				   		   on d.f_cliente_cod = e.cod
		   	            where (((b.dta_fim = '0001-01-01' or b.dta_fim is null or b.dta_fim >= current_date) and (lower(b.ind_ingr) = 'S')) 
				   	       or  ((b.dta_fim = '0001-01-01' or b.dta_fim is null or b.dta_fim >= current_date))
						   or  ((b.dta_fim = '0001-01-01' or b.dta_fim is null or b.dta_fim >= current_date)))
						  and c.cod_tipo = 1	

	) by db2;

	   create table &temp._pss_1 as
		     select *
		       from connection to db2(select * from &usuario..&temp._g1)
	      where not missing(cd_cli_relac) 
	       order by cd_cli, tip_rlc; 

disconnect from db2;
quit; 

data &temp._pss_2;
set &temp._pss_1;


if cd_cli = cd_cli_relac then irreg_comp_sco = 1; else irreg_comp_sco = 0;

run;


proc sql;
create table &temp._pss_3  as
      select distinct a.cd_cli, b.irreg_comp_sco
	  	from &temp._pss_2 as a 
   left join (select distinct cd_cli, max(irreg_comp_sco) as irreg_comp_sco from &temp._pss_2 group by cd_cli) as b
   	      on a.cd_cli = b.cd_cli
;
quit;


proc sql;
   create table v1  as
select distinct a.*
		  	  , max(0, b.ind_falecido) as ind_falecido
		  	  , e.ind_cpf_nao_tit
		  	  , e.ind_inexist_cpf_cnpj
			  , max(0, g.ind_cpf_cnpj_nao_regular) as ind_cpf_cnpj_nao_regular
			  , i.ind_ausc_scr
	  	   from &temp._pss_1 as a 

			/*Sócio Falecido*/
      left join (select a.f_pessjuri_cliente as cd_cli, a.f_cliente_cod as cd_cli_relac
		   		      , sum(case when c.cd_tip_anot in (129, 53) then 1 else 0 end) as ind_falecido 
			       from db2mci.participacao_cptl as a
		     inner join db2mci.cliente as b
			  	     on a.f_cliente_cod = b.cod 
	         inner join db2acp.anot_cadl_itno_pss as c
			  	     on b.cod_cpf_cgc = c.nr_ctre_srf
	  	   	      where c.cd_tip_anot in (129, 53)
			   group by a.f_pessjuri_cliente, a.f_cliente_cod) as b
	                 on a.cd_cli_relac = b.cd_cli_relac and a.cd_cli = b.cd_cli 

			/*Sócio com CPF não titular ou sem CPF/CNPJ*/	
      left join (select f_pessjuri_cliente as cd_cli, a.f_cliente_cod as cd_cli_relac 
				      , case when cod_ttdd_cpf = 2 then 1 else 0 end as ind_cpf_nao_tit
				      , case when cod_cpf_cgc is null or cod_cpf_cgc = 0 then 1 else 0 end as ind_inexist_cpf_cnpj
		           from db2mci.participacao_cptl as a
  	         inner join db2mci.cliente as b
		  	         on a.f_cliente_cod = b.cod
 	 		   group by f_pessjuri_cliente, cod_ttdd_cpf, cod_cpf_cgc) as e
   		     on a.cd_cli = e.cd_cli and a.cd_cli_relac = e.cd_cli_relac

			/*Sócio com CPF/CNPJ diferente de "regular"*/			
	   left join (select a.f_pessjuri_cliente as cd_cli, a.f_cliente_cod as cd_cli_relac 
	                   , 1 as ind_cpf_cnpj_nao_regular
					from db2mci.participacao_cptl as a
			  inner join db2mci.cliente as b
					  on a.f_cliente_cod = b.cod
			  inner join db2mci.cpf_base as c
				   	  on b.cod_cpf_cgc = c.num_cpf
			  inner join db2mci.cnpj_base as d
					  on b.cod_cpf_cgc = d.num_cnpj
				   where c.cod_situ <> 0 or d.cod_situ <> '2'
				group by a.f_pessjuri_cliente, a.f_cliente_cod) as g
			  on a.cd_cli = g.cd_cli and a.cd_cli_relac = g.cd_cli_relac

					/*Sócios sem autorização SCR*/	
	left join (select a.f_pessjuri_cliente as cd_cli, a.f_cliente_cod as cd_cli_relac 
				    , min(case when b.cod = 2 then 0 else 1 end) as ind_ausc_scr
				 from db2mci.participacao_cptl as a
			left join db2mci.caract_especial as b
				   on a.f_cliente_cod = b.f_cliente_cod
			 group by a.f_pessjuri_cliente, a.f_cliente_cod) as i
		  on a.cd_cli = i.cd_cli and a.cd_cli_relac = i.cd_cli_relac



order by cd_cli	   
;
quit;

proc sql;
   create table v2  as
select distinct a.cd_cli
/*			  , a.cd_grupo */
	  	      , min(1, max(b.ind_falecido)) as ind_falecido
			  , max(b.ind_cpf_nao_tit) as ind_cpf_nao_tit
			  , max(b.ind_inexist_cpf_cnpj) as ind_inexist_cpf_cnpj	
			  , max(b.ind_cpf_cnpj_nao_regular) as ind_cpf_cnpj_nao_regular
			  , max(b.ind_ausc_scr) as ind_ausc_scr
		      , c.prc_cptl_totl format 8.4
			  , c.prc_acao_ordn format 8.4 	
	  		
	       from &usuario..&temp._tabela_grp_2 as a
	  left join v1 as b
	         on a.cd_cli = b.cd_cli
      left join (select distinct cd_cli
	  						   , sum(prc_cptl_totl) as prc_cptl_totl
			  				   , sum(prc_acao_ordn) as prc_acao_ordn 		
					        from &temp._pss_1
					    group by cd_cli) as c
	         on a.cd_cli = c.cd_cli


group by a.cd_cli, c.prc_cptl_totl, c.prc_acao_ordn  
/*order by a.cd_grupo	 */
/*		  where*/
;
quit; 


proc stdize data=v2 out=v3 reponly missing=0; run;
	  	

data v4;
 set v3;	

  if prc_cptl_totl < 100 and prc_acao_ordn < 100 
then ind_sco_cptl_inf_100 = 1; 
else ind_sco_cptl_inf_100 = 0;

  if ind_falecido > 0 or 
	 ind_cpf_nao_tit > 0 or 
	 ind_inexist_cpf_cnpj > 0 or 
	 ind_cpf_cnpj_nao_regular > 0 or
	 ind_ausc_scr > 0 or
 	 ind_sco_cptl_inf_100 > 0 

then irreg_sco = 1; 
else irreg_sco = 0;
 			

run;


proc sql;
    create table v5 as
 select distinct a.cd_cli
			   , b.cd_est_lmcr
			   , b.vl_lim_crd_apvd format commax25.2
			   , b.cd_rsco_crd_cli, b.dt_vnct_ppto, b.cd_mtdl_anl_crd
			   , c.fba format commax25.2
			   , c.fm  format commax25.2
			   , d.Cod_Atvd
		       , d.Atividade
		       , d.Cod_Setor 
		   	   , d.Nom_Macro as Setor
		   	   , d.Segmento
		       , d.Estado
		   	   , d.Regiao
	  	    from v4 as a
	   left join apvd  as b on a.cd_cli = b.cd_cli
	   left join fatm  as c on a.cd_cli = c.cd_cli		
	   left join setor as d on a.cd_cli = d.cd_cli	
		   where a.irreg_sco = 0 
			 and a.cd_cli not in ( select distinct cd_cli from &temp._pss_3  where irreg_comp_sco = 1)
;
quit;


data v6;
 set v5;

  	 if     			   fba <=  50000000 then porte = 'FBA    <  50MM'; 
else if fba > 50000000 and fba <=  60000000 then porte = 'FBA 50 a 	60MM'; 
else if fba > 60000000 and fba <=  70000000 then porte = 'FBA 60 a 	70MM'; 
else if fba > 70000000 and fba <=  80000000 then porte = 'FBA 70 a 	80MM'; 
else if fba > 80000000 and fba <=  90000000 then porte = 'FBA 80 a  90MM'; 
else if fba > 90000000 and fba <= 100000000 then porte = 'FBA 90 a 100MM'; 

run;

/*4141*/
/*2829*/
data v7;
 set v6 ;
 where (CD_MTDL_ANL_CRD IN (/*60, 61,*/ 80, 81, 82, 83, 84, 85, 86, 87, 88, 89));
/* keep cd_cli CD_MTDL_ANL_CRD dt_vnct_ppto dt_vcnt dt_vcnt2;*/
/*  dt_vcnt = put(dt_vnct_ppto, monyy7.);*/
mes = month(dt_vnct_ppto);
ano =  year(dt_vnct_ppto);
dt_vcnt = mdy(mes, 1, ano);
    format dt_vcnt date9.;
    datalines;
 run;
;

PROC SQL;
	CREATE TABLE	v8 AS
	SELECT			count(cd_cli) as QTD_62
	FROM			db2anc.lmcr_cli
	WHERE			CD_MTDL_ANL_CRD = 62
	and DT_APVC_LIM >= '1Jul2025'd;
	;
QUIT;

PROC SQL;
	CREATE TABLE	v9 AS
	SELECT A.QTD_PUB_62, 
			B.QTD_62,
			SUM(A.QTD_PUB_62 , B.QTD_62) AS QTD_TOT_62,
		    (B.QTD_62/(SUM(A.QTD_PUB_62 , B.QTD_62))) AS ID_AUTOMACAO
	FROM ( SELECT	count(cd_cli) as QTD_PUB_62 FROM V7) A
	FULL JOIN		V8 B
	ON 1
	
	;
QUIT;

/*BUSCAR O REALIZADO DA AUTOMAÇÃO NA METODOLOGIA MASSIFICADA "HIGH EMPRESA" (MET. 62)*/

PROC SQL;
	CREATE TABLE	ID_AUTOMACAO_62 AS
	SELECT			"ANLTCO-PJ (50%)" as componente, ID_AUTOMACAO as indicador format percent10.3
	FROM V9
	;
QUIT;
