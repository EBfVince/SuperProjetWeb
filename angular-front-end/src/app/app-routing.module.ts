import { NgModule } from '@angular/core';
import { RouterModule, Routes } from '@angular/router';

import { FormationComponent } from './formation/formation.component';
import { EtablissementComponent } from './etablissement/etablissement.component';
import { AvisMasterComponent} from './avis-master/avis-master.component';

const routes: Routes = [
  { path: '', redirectTo: '/', pathMatch: 'full' },
  { path: 'formation', component: FormationComponent },
  { path: 'etablissement', component: EtablissementComponent },
  { path: 'avisMaster', component: AvisMasterComponent }
];

@NgModule({
  imports: [RouterModule.forRoot(routes) ],
  exports: [RouterModule]
})
export class AppRoutingModule { }
