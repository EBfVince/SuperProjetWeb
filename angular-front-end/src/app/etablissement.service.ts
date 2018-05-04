import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';

import { Observable } from 'rxjs/Observable';

import { ActivatedRoute } from '@angular/router';
import { Location } from '@angular/common';

import { EtablissementComponent } from './etablissement/etablissement.component';

@Injectable()
export class EtablissementService {

  constructor(private http: HttpClient) { }

  getEtablissements() : Observable<EtablissementComponent[]> {
    return this.http.get<EtablissementComponent[]>('assets/etablissements.json');
  }


}

